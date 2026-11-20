import type { ReviewDraftData } from "../utils/draftStorage";
import { apiFetch } from "./client";
import { uploadPhoto } from "./photos";
import type { Review, ReviewInput, ReviewItemInput } from "./types";

export function createReview(poiId: string, input: ReviewInput): Promise<Review> {
  return apiFetch<Review>(`/api/pois/${encodeURIComponent(poiId)}/reviews`, {
    method: "POST",
    body: input,
  });
}

export function updateReviewVisibility(reviewId: string, isPublic: boolean): Promise<Review> {
  return apiFetch<Review>(`/api/reviews/${encodeURIComponent(reviewId)}/visibility`, {
    method: "PATCH",
    body: { isPublic },
  });
}

export function deleteReview(reviewId: string): Promise<void> {
  return apiFetch<void>(`/api/reviews/${encodeURIComponent(reviewId)}`, {
    method: "DELETE",
  });
}

export interface ReviewSubmitResumeState {
  review: Review;
  uploadedPhotoIds: string[];
}

/**
 * Thrown when the review record was created but one or more photo uploads failed,
 * allowing callers to retry only the remaining photos without duplicating the review.
 */
export class PartialReviewSubmitError extends Error {
  readonly causeError: unknown;
  readonly resumeState: ReviewSubmitResumeState;

  constructor(causeError: unknown, resumeState: ReviewSubmitResumeState) {
    const message = causeError instanceof Error ? causeError.message : "Failed to upload one or more photos.";
    super(message);
    this.name = "PartialReviewSubmitError";
    this.causeError = causeError;
    this.resumeState = resumeState;
  }
}

/**
 * Persists a ReviewDraftData (review metadata, ordered dish items, and all general + per-item photos).
 * Keeps item ordering synchronized with the returned ReviewItem UUIDs for photo attachment and supports
 * idempotent resumption when a partial photo-upload failure occurs.
 */
export async function submitReviewWithPhotos(
  poiId: string,
  draft: ReviewDraftData,
  isPublic: boolean,
  resumeState?: ReviewSubmitResumeState | null,
): Promise<Review> {
  const validDraftItems = draft.items.filter((item) => item.name.trim().length > 0);
  const itemPayloads: ReviewItemInput[] = validDraftItems.map((item) => {
    const normalizedPrice = item.priceAmount.trim().replace(",", ".");
    return {
      name: item.name.trim(),
      verdict: item.verdict,
      priceAmount: normalizedPrice || null,
      note: item.note.trim() || null,
    };
  });

  const createdReview =
    resumeState?.review ??
    (await createReview(poiId, {
      rating: draft.rating,
      body: draft.body.trim() || null,
      isPublic,
      priceLevel: draft.priceLevel,
      currency: draft.priceLevel !== null || validDraftItems.some((i) => i.priceAmount.trim()) ? draft.currency : null,
      vibes: draft.vibes,
      items: itemPayloads,
    }));

  const uploadedPhotoIds = [...(resumeState?.uploadedPhotoIds ?? [])];
  const createdItems = createdReview.items ?? [];

  for (let i = 0; i < validDraftItems.length; i += 1) {
    const itemPhotos = validDraftItems[i].photos;
    const createdItem = createdItems[i];
    if (!createdItem || itemPhotos.length === 0) {
      continue;
    }
    for (const photo of itemPhotos) {
      if (uploadedPhotoIds.includes(photo.id)) {
        continue;
      }
      try {
        await uploadPhoto(poiId, {
          file: photo.file,
          reviewId: createdReview.id,
          reviewItemId: createdItem.id,
          isPublic,
        });
        uploadedPhotoIds.push(photo.id);
      } catch (err) {
        throw new PartialReviewSubmitError(err, {
          review: createdReview,
          uploadedPhotoIds,
        });
      }
    }
  }

  for (const photo of draft.photos) {
    if (uploadedPhotoIds.includes(photo.id)) {
      continue;
    }
    try {
      await uploadPhoto(poiId, {
        file: photo.file,
        reviewId: createdReview.id,
        isPublic,
      });
      uploadedPhotoIds.push(photo.id);
    } catch (err) {
      throw new PartialReviewSubmitError(err, {
        review: createdReview,
        uploadedPhotoIds,
      });
    }
  }

  return createdReview;
}
