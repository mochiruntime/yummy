import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Check, Globe, History, Lock, Pencil, Plus, Star, Trash2, X } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { ApiError } from "../../api/client";
import { addPoiToList, removePoiFromList } from "../../api/lists";
import { deletePoi, fetchPoiDetail, poiKeys, updatePoiVisibility } from "../../api/pois";
import {
  deleteReview,
  PartialReviewSubmitError,
  submitReviewWithPhotos,
  updateReviewVisibility,
  type ReviewSubmitResumeState,
} from "../../api/reviews";
import type { Photo, PoiDetail, PoiList, Review, User } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { deleteDraft, loadDraft, saveDraft, type ReviewDraftData } from "../../utils/draftStorage";
import { ListIcon } from "../lists/ListIcon";
import { createEmptyReviewDraft, ReviewComposer } from "../reviews/ReviewComposer";

const VERDICT_BADGES: Record<string, string> = {
  must_get: "🤤 Must-get",
  good: "👌 Good",
  skip: "😐 Skip",
};

function PoiReviewsSection({
  detail,
  currentUser,
  isAdmin,
}: {
  detail: PoiDetail;
  currentUser: User | null;
  isAdmin: boolean;
}) {
  const queryClient = useQueryClient();
  const draftKey = currentUser ? `yummy:draft:review:${currentUser.id}:${detail.id}` : null;

  const [reviewDraft, setReviewDraft] = useState<ReviewDraftData>(createEmptyReviewDraft);
  const [reviewPublic, setReviewPublic] = useState(false);
  const [availableDraft, setAvailableDraft] = useState<ReviewDraftData | null>(null);
  const [reviewResumeForRetry, setReviewResumeForRetry] = useState<ReviewSubmitResumeState | null>(null);

  useEffect(() => {
    if (!draftKey) {
      return;
    }
    let active = true;
    void loadDraft<ReviewDraftData>(draftKey).then((found) => {
      if (active && found && found.rating > 0) {
        setAvailableDraft(found);
      }
    });
    return () => {
      active = false;
    };
  }, [draftKey]);

  useEffect(() => {
    if (!draftKey || reviewDraft.rating === 0) {
      return;
    }
    const timer = setTimeout(() => {
      void saveDraft(draftKey, reviewDraft);
    }, 400);
    return () => clearTimeout(timer);
  }, [draftKey, reviewDraft]);

  const submitReviewMutation = useMutation({
    mutationFn: async () => {
      try {
        return await submitReviewWithPhotos(
          detail.id,
          reviewDraft,
          reviewPublic,
          reviewResumeForRetry,
        );
      } catch (err) {
        if (err instanceof PartialReviewSubmitError) {
          setReviewResumeForRetry(err.resumeState);
        }
        throw err;
      }
    },
    onSuccess: async () => {
      setReviewResumeForRetry(null);
      if (draftKey) {
        await deleteDraft(draftKey);
      }
      setReviewDraft(createEmptyReviewDraft());
      setReviewPublic(false);
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(detail.id) });
    },
  });

  const toggleReviewVisibilityMutation = useMutation({
    mutationFn: ({ reviewId, nextPublic }: { reviewId: string; nextPublic: boolean }) =>
      updateReviewVisibility(reviewId, nextPublic),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(detail.id) });
    },
  });

  const deleteReviewMutation = useMutation({
    mutationFn: (reviewId: string) => deleteReview(reviewId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(detail.id) });
    },
  });

  const photosByItemId = useMemo(() => {
    const map = new Map<string, Photo[]>();
    for (const photo of detail.photos ?? []) {
      if (photo.reviewItemId) {
        const list = map.get(photo.reviewItemId) ?? [];
        list.push(photo);
        map.set(photo.reviewItemId, list);
      }
    }
    return map;
  }, [detail.photos]);

  const generalPhotos = useMemo(
    () => (detail.photos ?? []).filter((p) => !p.reviewItemId),
    [detail.photos],
  );

  const reviews: Review[] = detail.reviews ?? [];
  const submitError =
    submitReviewMutation.error instanceof ApiError
      ? submitReviewMutation.error.message
      : submitReviewMutation.error instanceof Error
        ? submitReviewMutation.error.message
        : null;

  return (
    <div className="space-y-4 border-t border-black/10 pt-3 dark:border-white/10">
      {generalPhotos.length > 0 && (
        <div className="space-y-1.5">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
            Photos ({generalPhotos.length})
          </h3>
          <div className="flex flex-wrap gap-2">
            {generalPhotos.map((photo) => (
              <a
                key={photo.id}
                href={photo.url}
                target="_blank"
                rel="noreferrer"
                className="block h-16 w-16 overflow-hidden rounded-lg border border-black/10 dark:border-white/10"
              >
                <img
                  src={photo.thumbUrl}
                  alt={photo.caption ?? detail.name}
                  className="h-full w-full object-cover"
                />
              </a>
            ))}
          </div>
        </div>
      )}

      {currentUser && (
        <div className="space-y-2">
          {availableDraft && (
            <div
              role="status"
              className="flex items-center justify-between gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200"
            >
              <span>Unsaved review draft found.</span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => {
                    setReviewDraft(availableDraft);
                    setAvailableDraft(null);
                  }}
                  className="font-semibold underline"
                >
                  Restore
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setAvailableDraft(null);
                    if (draftKey) {
                      void deleteDraft(draftKey);
                    }
                  }}
                  className="text-[var(--text-muted)] underline"
                >
                  Discard
                </button>
              </div>
            </div>
          )}

          <ReviewComposer
            value={reviewDraft}
            onChange={setReviewDraft}
            title="Leave a review"
            subtitle={detail.name}
          />

          {reviewDraft.rating > 0 && (
            <div className="space-y-2">
              <div className="flex items-center justify-between gap-2 text-xs">
                <span className="text-[var(--text-muted)]">
                  {reviewPublic ? "Visible to guests (if spot is public)" : "Friends Only"}
                </span>
                <button
                  type="button"
                  onClick={() => setReviewPublic((v) => !v)}
                  className="min-h-8 rounded-lg border border-black/15 px-2.5 text-xs font-semibold dark:border-white/15"
                >
                  {reviewPublic ? "Make Friends Only" : "Make Public"}
                </button>
              </div>

              {submitError && (
                <p role="alert" className="text-xs text-[var(--accent-brand)]">
                  {submitError}
                </p>
              )}

              <button
                type="button"
                disabled={submitReviewMutation.isPending}
                onClick={() => submitReviewMutation.mutate()}
                className="min-h-10 w-full rounded-lg bg-[var(--accent-brand)] px-4 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50"
              >
                {submitReviewMutation.isPending
                  ? "Posting review…"
                  : `Post ${reviewDraft.rating}★ review`}
              </button>
            </div>
          )}
        </div>
      )}

      <div className="space-y-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
          Reviews ({reviews.length})
        </h3>
        {reviews.length === 0 ? (
          <p className="text-xs text-[var(--text-muted)]">No reviews yet.</p>
        ) : (
          <ul className="space-y-2.5" aria-label="Reviews">
            {reviews.map((review) => {
              const items = review.items ?? [];
              const vibes = review.vibes ?? [];
              const canModerateReview = Boolean(
                currentUser && (isAdmin || currentUser.id === review.authorId),
              );
              return (
                <li
                  key={review.id}
                  className="space-y-2 rounded-xl border border-black/10 p-3 text-xs dark:border-white/10"
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold">{review.authorName}</span>
                      <span className="inline-flex items-center gap-0.5 font-bold text-amber-600 dark:text-amber-400">
                        <Star aria-hidden="true" className="h-3.5 w-3.5" fill="currentColor" />
                        {review.rating}
                      </span>
                      {review.priceLevel && (
                        <span className="rounded bg-black/5 px-1.5 py-0.5 text-[11px] font-semibold dark:bg-white/10">
                          {"$".repeat(review.priceLevel)}
                          {review.currency ? ` · ${review.currency}` : ""}
                        </span>
                      )}
                    </div>
                    <span className="text-[11px] text-[var(--text-muted)]">
                      {new Date(review.createdAt).toLocaleDateString()}
                    </span>
                  </div>

                  {vibes.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                      {vibes.map((vibe) => (
                        <span
                          key={vibe}
                          className="rounded-full bg-[var(--accent-brand)]/10 px-2 py-0.5 text-[11px] font-medium text-[var(--accent-brand)]"
                        >
                          {vibe}
                        </span>
                      ))}
                    </div>
                  )}

                  {review.body && <p className="whitespace-pre-wrap text-sm">{review.body}</p>}

                  {items.length > 0 && (
                    <ul className="space-y-1.5 border-t border-black/5 pt-2 dark:border-white/5">
                      {items.map((item) => {
                        const itemPhotos = photosByItemId.get(item.id) ?? [];
                        return (
                          <li
                            key={item.id}
                            className="space-y-1 rounded-lg bg-black/[0.03] p-2 dark:bg-white/[0.03]"
                          >
                            <div className="flex items-center justify-between gap-2">
                              <span className="font-semibold">{item.name}</span>
                              <div className="flex items-center gap-1.5">
                                {item.priceAmount && (
                                  <span className="text-[11px] text-[var(--text-muted)]">
                                    {review.currency ?? ""} {item.priceAmount}
                                  </span>
                                )}
                                <span className="rounded-full border border-black/10 px-2 py-0.5 text-[10px] font-semibold dark:border-white/10">
                                  {VERDICT_BADGES[item.verdict] ?? item.verdict}
                                </span>
                              </div>
                            </div>
                            {item.note && (
                              <p className="text-[11px] text-[var(--text-muted)]">{item.note}</p>
                            )}
                            {itemPhotos.length > 0 && (
                              <div className="flex flex-wrap gap-1.5 pt-1">
                                {itemPhotos.map((p) => (
                                  <a
                                    key={p.id}
                                    href={p.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="block h-12 w-12 overflow-hidden rounded-md border border-black/10 dark:border-white/10"
                                  >
                                    <img
                                      src={p.thumbUrl}
                                      alt={item.name}
                                      className="h-full w-full object-cover"
                                    />
                                  </a>
                                ))}
                              </div>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  )}

                  {canModerateReview && (
                    <div className="flex items-center justify-end gap-2 pt-1 text-[11px]">
                      <button
                        type="button"
                        onClick={() =>
                          toggleReviewVisibilityMutation.mutate({
                            reviewId: review.id,
                            nextPublic: !review.isPublic,
                          })
                        }
                        className="text-[var(--text-muted)] underline hover:text-[var(--text-primary)]"
                      >
                        {review.isPublic ? "Make Friends Only" : "Make Public"}
                      </button>
                      <button
                        type="button"
                        onClick={() => deleteReviewMutation.mutate(review.id)}
                        className="text-[var(--accent-brand)] underline"
                      >
                        Delete review
                      </button>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
export interface PoiDetailPanelProps {
  poiId: string;
  lists: PoiList[];
  currentUser: User | null;
  isAdmin: boolean;
  variant?: "inline" | "drawer";
  onBack: () => void;
  onEdit: (detail: PoiDetail) => void;
  onOpenHistory: (detail: PoiDetail) => void;
  onDeleted: (poiId: string) => void;
}

function DetailHeaderAction({
  variant,
  onBack,
}: {
  variant: "inline" | "drawer";
  onBack: () => void;
}) {
  if (variant === "drawer") {
    return (
      <button
        type="button"
        aria-label="Close spot details"
        onClick={onBack}
        className="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
      >
        <X aria-hidden="true" className="h-4 w-4" />
        Close
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={onBack}
      className="inline-flex min-h-11 items-center gap-1.5 text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-primary)]"
    >
      <ArrowLeft aria-hidden="true" className="h-4 w-4" />
      Back to spots
    </button>
  );
}

export function PoiDetailPanel({
  poiId,
  lists,
  currentUser,
  isAdmin,
  variant = "inline",
  onBack,
  onEdit,
  onOpenHistory,
  onDeleted,
}: PoiDetailPanelProps) {
  const queryClient = useQueryClient();
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const detailQuery = useQuery({
    queryKey: poiKeys.detail(poiId),
    queryFn: ({ signal }) => fetchPoiDetail(poiId, signal),
    retry: false,
  });

  const visibilityMutation = useMutation({
    mutationFn: (nextPublic: boolean) => updatePoiVisibility(poiId, nextPublic),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(poiId) });
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
    },
  });

  const membershipMutation = useMutation({
    mutationFn: ({ listId, attached }: { listId: string; attached: boolean }) =>
      attached ? removePoiFromList(listId, poiId) : addPoiToList(listId, poiId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(poiId) });
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => deletePoi(poiId),
    onSuccess: () => {
      queryClient.removeQueries({ queryKey: poiKeys.detail(poiId) });
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
      onDeleted(poiId);
    },
  });

  const manageableLists = useMemo(() => {
    if (!currentUser) {
      return [];
    }
    return isAdmin ? lists : lists.filter((l) => l.ownerId === currentUser.id);
  }, [lists, currentUser, isAdmin]);

  if (detailQuery.isPending) {
    return (
      <div className="space-y-3">
        <DetailHeaderAction variant={variant} onBack={onBack} />
        <p className="py-6 text-center text-sm text-[var(--text-muted)]">Loading spot details…</p>
      </div>
    );
  }

  if (detailQuery.isError || !detailQuery.data) {
    const isNotFound =
      detailQuery.error instanceof ApiError && detailQuery.error.status === 404;

    return (
      <div className="space-y-3">
        <DetailHeaderAction variant={variant} onBack={onBack} />
        <p role="alert" className="rounded-xl border border-black/10 p-4 text-sm text-[var(--text-muted)] dark:border-white/10">
          {isNotFound ? "Spot not found." : "Unable to load spot details."}
        </p>
      </div>
    );
  }

  const detail = detailQuery.data;
  const canManage = Boolean(currentUser && (isAdmin || detail.createdBy.id === currentUser.id));
  const attachedListIds = new Set(detail.lists.map((l) => l.id));

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <DetailHeaderAction variant={variant} onBack={onBack} />
        <span className="text-xs font-mono text-[var(--text-muted)]">v{detail.version}</span>
      </div>

      <div className="space-y-2">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <h2 className="text-lg font-bold leading-snug">{detail.name}</h2>
          <span
            className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${
              detail.isPublic
                ? "bg-[var(--vis-public)]/15 text-[var(--vis-public)]"
                : "bg-[var(--vis-private)]/15 text-[var(--vis-private)]"
            }`}
          >
            {detail.isPublic ? (
              <>
                <Globe aria-hidden="true" className="h-3.5 w-3.5" />
                Public
              </>
            ) : (
              <>
                <Lock aria-hidden="true" className="h-3.5 w-3.5" />
                Friends Only
              </>
            )}
          </span>
        </div>

        {detail.address && (
          <p className="text-xs text-[var(--text-muted)]">{detail.address}</p>
        )}

        <p className="text-xs text-[var(--text-muted)]">
          Added by {detail.createdBy.displayName ?? "Member"} ·{" "}
          {detail.latitude.toFixed(5)}, {detail.longitude.toFixed(5)}
        </p>
      </div>

      {detail.description && (
        <p className="whitespace-pre-wrap rounded-xl bg-black/5 p-3 text-sm leading-relaxed dark:bg-white/5">
          {detail.description}
        </p>
      )}

      {/* Visibility toggle for creator / Admin */}
      {canManage && (
        <div className="flex items-center justify-between gap-2 rounded-xl border border-black/10 px-3 py-2 dark:border-white/10">
          <span className="text-xs text-[var(--text-muted)]">
            {detail.isPublic ? "Visible to guests" : "Hidden from guests"}
          </span>
          <button
            type="button"
            disabled={visibilityMutation.isPending}
            onClick={() => visibilityMutation.mutate(!detail.isPublic)}
            className="min-h-9 rounded-lg border border-black/15 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
          >
            {detail.isPublic ? "Make Friends Only" : "Make Public"}
          </button>
        </div>
      )}

      {/* Lists display + owner-scoped membership toggles */}
      <div className="space-y-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
          Lists
        </h3>

        {detail.lists.length === 0 && manageableLists.length === 0 && (
          <p className="text-xs text-[var(--text-muted)]">Not in any list yet.</p>
        )}

        <div className="flex flex-wrap gap-1.5">
          {detail.lists
            .filter((l) => !manageableLists.some((ml) => ml.id === l.id))
            .map((list) => (
              <span
                key={list.id}
                className="inline-flex min-h-9 items-center gap-1 rounded-full px-3 text-xs font-medium text-white"
                style={{ backgroundColor: sanitizeHexColor(list.color) }}
              >
                <ListIcon name={list.icon} className="h-3.5 w-3.5" />
                {list.name}
              </span>
            ))}

          {manageableLists.map((list) => {
            const attached = attachedListIds.has(list.id);
            const color = sanitizeHexColor(list.color);
            return (
              <button
                key={list.id}
                type="button"
                aria-pressed={attached}
                disabled={membershipMutation.isPending}
                onClick={() => membershipMutation.mutate({ listId: list.id, attached })}
                className={`inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors ${
                  attached
                    ? "border-transparent text-white"
                    : "border-black/15 text-[var(--text-primary)] hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/5"
                }`}
                style={attached ? { backgroundColor: color } : undefined}
              >
                <ListIcon name={list.icon} className="h-3.5 w-3.5" />
                <span>{list.name}</span>
                {attached ? (
                  <Check aria-hidden="true" className="h-3.5 w-3.5" />
                ) : (
                  <Plus aria-hidden="true" className="h-3.5 w-3.5" />
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Member & Creator/Admin actions */}
      {currentUser && (
        <div className="space-y-2 border-t border-black/10 pt-3 dark:border-white/10">
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => onEdit(detail)}
              className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/15 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
            >
              <Pencil aria-hidden="true" className="h-3.5 w-3.5" />
              Edit
            </button>

            <button
              type="button"
              onClick={() => onOpenHistory(detail)}
              className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/15 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
            >
              <History aria-hidden="true" className="h-3.5 w-3.5" />
              History
            </button>

            {canManage && !confirmingDelete && (
              <button
                type="button"
                onClick={() => setConfirmingDelete(true)}
                className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--accent-brand)]/30 px-3 text-xs font-semibold text-[var(--accent-brand)] hover:bg-[var(--accent-brand)]/10"
              >
                <Trash2 aria-hidden="true" className="h-3.5 w-3.5" />
                Delete
              </button>
            )}
          </div>

          {canManage && confirmingDelete && (
            <div className="flex items-center justify-between gap-2 rounded-xl border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 p-3 text-xs">
              <span className="font-medium text-[var(--accent-brand)]">Delete this spot?</span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setConfirmingDelete(false)}
                  className="min-h-9 rounded-lg border border-black/15 bg-[var(--surface-primary)] px-3 font-semibold dark:border-white/15"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={deleteMutation.isPending}
                  onClick={() => deleteMutation.mutate()}
                  className="min-h-9 rounded-lg bg-[var(--accent-brand)] px-3 font-semibold text-white"
                >
                  {deleteMutation.isPending ? "Deleting…" : "Confirm Delete"}
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      <PoiReviewsSection key={detail.id} detail={detail} currentUser={currentUser} isAdmin={isAdmin} />
    </div>
  );
}
