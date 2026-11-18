<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Photo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Processes uploaded POI and review photos, stripping all EXIF/GPS metadata and generating variants.
 *
 * Security & Privacy Guarantees:
 * - Validates MIME types strictly against an allowlist (image/jpeg, image/png, image/webp).
 * - Auto-orients images according to EXIF orientation BEFORE stripping metadata so portrait photos stay upright.
 * - Strips all EXIF, IPTC, and XMP profiles (including GPS coordinates, camera make/model, and timestamps).
 * - Stores sanitized images outside the public web root under random 256-bit hex storage keys.
 */
final class ImageProcessingService
{
    private const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const FULL_MAX_DIMENSION = 2048;
    private const THUMB_MAX_DIMENSION = 400;

    /**
     * Map of allowed MIME types to canonical file extensions and Imagick format identifiers.
     *
     * @var array<string, array{ext: string, format: string}>
     */
    private const ALLOWED_MIME_MAP = [
        'image/jpeg' => ['ext' => 'jpg', 'format' => 'jpeg'],
        'image/png' => ['ext' => 'png', 'format' => 'png'],
        'image/webp' => ['ext' => 'webp', 'format' => 'webp'],
    ];

    public function __construct(
        private readonly string $photoStorageDir,
    ) {
    }

    /**
     * Validates, strips EXIF/GPS metadata, and writes full (<=2048px) and thumb (<=400px) variants to disk.
     *
     * @param UploadedFile $file Uploaded multipart image file
     *
     * @return array{storageKey: string, mimeType: string, width: int, height: int}
     *
     * @throws \InvalidArgumentException When the file is invalid, oversized, or an unsupported format
     * @throws \RuntimeException         When image processing or disk persistence fails
     */
    public function processAndStore(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Uploaded file is invalid or incomplete.');
        }

        $size = $file->getSize();
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Image file size must be between 1 byte and 10 MB.');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !isset(self::ALLOWED_MIME_MAP[$mimeType])) {
            throw new \InvalidArgumentException('Unsupported image format. Allowed formats: JPEG, PNG, WebP.');
        }

        $formatInfo = self::ALLOWED_MIME_MAP[$mimeType];
        $storageKey = bin2hex(random_bytes(16));

        $this->ensureStorageDirectoryExists();

        $fullPath = $this->buildVariantPath($storageKey, 'full', $formatInfo['ext']);
        $thumbPath = $this->buildVariantPath($storageKey, 'thumb', $formatInfo['ext']);

        if (!class_exists(\Imagick::class)) {
            throw new \RuntimeException('Imagick extension is required for EXIF-stripped image processing.');
        }

        try {
            $imagick = new \Imagick($file->getPathname());

            // Auto-orient pixels using EXIF Orientation tag BEFORE stripping EXIF metadata
            $imagick->autoOrient();

            // Strip all EXIF, IPTC, XMP, and comment chunks (eliminates GPS coordinates & device serials)
            $imagick->stripImage();
            $imagick->setImageFormat($formatInfo['format']);

            if ($formatInfo['format'] === 'jpeg' || $formatInfo['format'] === 'webp') {
                $imagick->setImageCompressionQuality(85);
            }

            // Generate full-size variant (downscale only if exceeding 2048px on longest side)
            $this->scaleDownIfNeeded($imagick, self::FULL_MAX_DIMENSION);
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $imagick->writeImage($fullPath);

            // Generate thumbnail variant (max 400px on longest side)
            $thumb = clone $imagick;
            $this->scaleDownIfNeeded($thumb, self::THUMB_MAX_DIMENSION);
            $thumb->writeImage($thumbPath);

            $thumb->clear();
            $imagick->clear();

            return [
                'storageKey' => $storageKey,
                'mimeType' => $mimeType,
                'width' => $width,
                'height' => $height,
            ];
        } catch (\ImagickException $e) {
            @unlink($fullPath);
            @unlink($thumbPath);
            throw new \InvalidArgumentException('Unable to decode uploaded image file.', 0, $e);
        }
    }

    /**
     * Resolves the filesystem path for a stored photo variant ("full" or "thumb").
     *
     * @param Photo  $photo Photo entity
     * @param string $size  Variant identifier ("full" or "thumb")
     */
    public function resolveFilePath(Photo $photo, string $size): string
    {
        $variant = $size === 'thumb' ? 'thumb' : 'full';
        $ext = self::ALLOWED_MIME_MAP[$photo->getMimeType()]['ext'] ?? 'jpg';

        return $this->buildVariantPath($photo->getStorageKey(), $variant, $ext);
    }

    /**
     * Removes both full and thumbnail image files from disk for a deleted photo.
     */
    public function deleteFiles(Photo $photo): void
    {
        foreach (['full', 'thumb'] as $variant) {
            $path = $this->resolveFilePath($photo, $variant);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Scales an Imagick instance proportionally only when width or height exceeds $maxDimension.
     */
    private function scaleDownIfNeeded(\Imagick $image, int $maxDimension): void
    {
        $w = $image->getImageWidth();
        $h = $image->getImageHeight();

        if ($w > $maxDimension || $h > $maxDimension) {
            // bestfit=true maintains aspect ratio within the maxDimension x maxDimension box
            $image->resizeImage($maxDimension, $maxDimension, \Imagick::FILTER_LANCZOS, 1.0, true);
        }
    }

    /**
     * Ensures the private photo storage directory exists on disk.
     */
    private function ensureStorageDirectoryExists(): void
    {
        if (!is_dir($this->photoStorageDir) && !@mkdir($this->photoStorageDir, 0750, true) && !is_dir($this->photoStorageDir)) {
            throw new \RuntimeException(sprintf('Unable to create photo storage directory "%s".', $this->photoStorageDir));
        }
    }

    /**
     * Constructs a deterministic variant file path inside the private storage directory.
     */
    private function buildVariantPath(string $storageKey, string $variant, string $ext): string
    {
        return rtrim($this->photoStorageDir, '/\\').DIRECTORY_SEPARATOR.sprintf('%s_%s.%s', $storageKey, $variant, $ext);
    }
}
