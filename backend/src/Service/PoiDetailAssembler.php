<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poi;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PhotoRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReviewItemRepository;
use App\Repository\ReviewRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Assembles the full PoiDetail response payload with viewer-scoped social data.
 *
 * Keeps visibility filtering and reaction aggregation decoupled from the Poi Doctrine entity
 * so that unauthenticated guests only ever receive public child items (reviews, comments,
 * reactions, and photos whose parent POI and optional parent review are also public).
 */
final class PoiDetailAssembler
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly ReviewRepository $reviews,
        private readonly ReviewItemRepository $reviewItems,
        private readonly CommentRepository $comments,
        private readonly PhotoRepository $photos,
        private readonly ReactionRepository $reactions,
    ) {
    }

    /**
     * Builds the associative array matching the OpenAPI PoiDetail schema for a given viewer.
     *
     * @param Poi       $poi    Target POI entity
     * @param User|null $viewer Current authenticated user, or null for unauthenticated guests
     *
     * @return array<string, mixed>
     */
    public function assemble(Poi $poi, ?User $viewer): array
    {
        $isGuest = ($viewer === null);

        /** @var array<string, mixed> $base */
        $base = $this->normalizer->normalize($poi, 'json', ['groups' => ['poi:read']]);

        $visibleReviews = $this->reviews->findVisibleForPoi($poi, $isGuest);
        /** @var list<array<string, mixed>> $normalizedReviews */
        $normalizedReviews = $this->normalizer->normalize(
            $visibleReviews,
            'json',
            ['groups' => ['review:read'], 'ignored_attributes' => ['items']],
        );
        $groupedItems = $this->reviewItems->findGroupedByReviews($visibleReviews);
        $reviewReactions = $this->reactions->getAggregatedForReviews($poi, $viewer);
        foreach ($normalizedReviews as &$reviewItem) {
            $reviewId = is_string($reviewItem['id'] ?? null) ? $reviewItem['id'] : '';
            $reviewItem['items'] = $this->normalizer->normalize(
                $groupedItems[$reviewId] ?? [],
                'json',
                ['groups' => ['review:read']],
            );
            $reviewItem['reactions'] = $reviewReactions[$reviewId] ?? [];
        }
        unset($reviewItem);
        $base['reviews'] = $normalizedReviews;

        $base['comments'] = $this->normalizer->normalize(
            $this->comments->findVisibleForPoi($poi, $isGuest),
            'json',
            ['groups' => ['comment:read']],
        );

        /** @var list<array<string, mixed>> $normalizedPhotos */
        $normalizedPhotos = $this->normalizer->normalize(
            $this->photos->findVisibleForPoi($poi, $isGuest),
            'json',
            ['groups' => ['photo:read']],
        );
        $photoReactions = $this->reactions->getAggregatedForPhotos($poi, $viewer);
        foreach ($normalizedPhotos as &$photoItem) {
            $photoId = is_string($photoItem['id'] ?? null) ? $photoItem['id'] : '';
            $photoItem['reactions'] = $photoReactions[$photoId] ?? [];
        }
        unset($photoItem);
        $base['photos'] = $normalizedPhotos;

        $base['reactions'] = $this->reactions->getAggregatedForPoi($poi, $viewer);

        return $base;
    }
}
