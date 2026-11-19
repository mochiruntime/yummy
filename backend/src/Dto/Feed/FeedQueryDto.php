<?php

declare(strict_types=1);

namespace App\Dto\Feed;

use App\Service\ActivityFeedService;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated query-string DTO for cursor-paginated activity feed requests.
 */
final class FeedQueryDto
{
    /**
     * Optional opaque base64url keyset cursor returned by a previous feed page.
     */
    public ?string $cursor = null;

    /**
     * Maximum number of feed events to return per page (1..100, default 30).
     */
    #[Assert\Range(min: 1, max: 100)]
    public int $limit = 30;

    /**
     * Validates that a non-empty cursor decodes cleanly into a timestamp and UUID pair.
     */
    #[Assert\Callback]
    public function validateCursor(ExecutionContextInterface $context): void
    {
        if ($this->cursor === null || trim($this->cursor) === '') {
            return;
        }

        if (ActivityFeedService::decodeCursor(trim($this->cursor)) === null) {
            $context->buildViolation('Invalid cursor.')
                ->atPath('cursor')
                ->addViolation();
        }
    }

    /**
     * Converts the validated cursor string into its decoded keyset components, or null when omitted.
     *
     * @return array{createdAt: \DateTimeImmutable, id: Uuid}|null
     */
    public function toCursor(): ?array
    {
        if ($this->cursor === null || trim($this->cursor) === '') {
            return null;
        }

        return ActivityFeedService::decodeCursor(trim($this->cursor));
    }
}
