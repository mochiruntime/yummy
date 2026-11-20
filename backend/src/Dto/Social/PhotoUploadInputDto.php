<?php

declare(strict_types=1);

namespace App\Dto\Social;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated multipart form metadata for uploading a photo to a POI.
 */
final class PhotoUploadInputDto
{
    /**
     * Optional caption describing the photo (max 255 characters).
     */
    #[Assert\Length(max: 255, maxMessage: 'Caption cannot exceed 255 characters.')]
    public ?string $caption = null;

    /**
     * Optional UUID of an existing review on the same POI to link this photo to.
     */
    #[Assert\Uuid(message: 'Invalid reviewId UUID.')]
    public ?string $reviewId = null;

    /**
     * Optional UUID of an existing ReviewItem on the same POI/review to link this dish photo to.
     */
    #[Assert\Uuid(message: 'Invalid reviewItemId UUID.')]
    public ?string $reviewItemId = null;

    /**
     * Public visibility flag (defaults to false for privacy).
     */
    public bool $isPublic = false;
}
