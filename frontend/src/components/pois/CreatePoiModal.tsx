import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Globe, Lock, MapPin, Search, X } from "lucide-react";
import { useEffect, useId, useMemo, useState, type FormEvent } from "react";
import { ApiError } from "../../api/client";
import { createPoi, poiKeys, searchGeocode } from "../../api/pois";
import {
  PartialReviewSubmitError,
  submitReviewWithPhotos,
  type ReviewSubmitResumeState,
} from "../../api/reviews";
import type { GeocodeResult, PoiDetail, PoiList, User } from "../../api/types";
import {
  deleteDraft,
  loadDraft,
  saveDraft,
  type CreatePoiDraftData,
  type ReviewDraftData,
} from "../../utils/draftStorage";
import { ListMultiSelect } from "../lists/ListMultiSelect";
import { createEmptyReviewDraft, ReviewComposer } from "../reviews/ReviewComposer";
import { Modal } from "../ui/Modal";

export interface CreatePoiModalProps {
  lists: PoiList[];
  currentUser: User;
  isAdmin: boolean;
  initialCoords: { lat: number; lng: number } | null;
  mapCenter?: { lat: number; lng: number } | null;
  onPickOnMap: () => void;
  onCreated: (poi: PoiDetail) => void;
  onClose: () => void;
}

interface CreatePoiFormProps extends CreatePoiModalProps {
  draftKey: string;
  initialDraft: CreatePoiDraftData | null;
  onUserInput?: () => void;
}

function CreatePoiForm({
  lists,
  currentUser,
  isAdmin,
  initialCoords,
  mapCenter = null,
  draftKey,
  initialDraft,
  onUserInput,
  onPickOnMap,
  onCreated,
}: CreatePoiFormProps) {
  const queryClient = useQueryClient();
  const searchId = useId();
  const nameId = useId();
  const addressId = useId();
  const descId = useId();

  const [geoQuery, setGeoQuery] = useState("");
  const [debouncedGeo, setDebouncedGeo] = useState("");
  const [name, setName] = useState(initialDraft?.name ?? "");
  const [address, setAddress] = useState(initialDraft?.address ?? "");
  const [description, setDescription] = useState(initialDraft?.description ?? "");
  const [latitude, setLatitude] = useState<string>(
    initialCoords ? initialCoords.lat.toFixed(6) : (initialDraft?.latitude ?? ""),
  );
  const [longitude, setLongitude] = useState<string>(
    initialCoords ? initialCoords.lng.toFixed(6) : (initialDraft?.longitude ?? ""),
  );
  const [selectedListIds, setSelectedListIds] = useState<string[]>(
    initialDraft?.selectedListIds ?? [],
  );
  const [isPublic, setIsPublic] = useState(initialDraft?.isPublic ?? false);
  const [reviewDraft, setReviewDraft] = useState<ReviewDraftData>(
    initialDraft?.review ?? createEmptyReviewDraft(),
  );
  const [createdPoiForRetry, setCreatedPoiForRetry] = useState<PoiDetail | null>(null);
  const [reviewResumeForRetry, setReviewResumeForRetry] = useState<ReviewSubmitResumeState | null>(null);

  // FR-POI-02: Only allow attaching new POIs to lists owned by the user (or any list for Admin).
  const assignableLists = useMemo(
    () => (isAdmin ? lists : lists.filter((l) => l.ownerId === currentUser.id)),
    [lists, isAdmin, currentUser.id],
  );

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedGeo(geoQuery.trim()), 300);
    return () => clearTimeout(timer);
  }, [geoQuery]);

  // Auto-save form + review + unsaved photo Blobs to IndexedDB every 400ms.
  useEffect(() => {
    const hasContent =
      name.trim().length > 0 ||
      address.trim().length > 0 ||
      description.trim().length > 0 ||
      selectedListIds.length > 0 ||
      reviewDraft.rating > 0;
    if (!hasContent) {
      return;
    }

    if (!initialDraft) {
      onUserInput?.();
    }

    const timer = setTimeout(() => {
      const payload: CreatePoiDraftData = {
        name,
        address,
        description,
        latitude,
        longitude,
        selectedListIds,
        isPublic,
        review: reviewDraft,
        updatedAt: Date.now(),
      };
      void saveDraft(draftKey, payload);
    }, 400);

    return () => clearTimeout(timer);
  }, [
    draftKey,
    name,
    address,
    description,
    latitude,
    longitude,
    selectedListIds,
    isPublic,
    reviewDraft,
    initialDraft,
    onUserInput,
  ]);

  const geocodeQuery = useQuery({
    queryKey: ["geocode", "create-poi", debouncedGeo, mapCenter?.lat ?? null, mapCenter?.lng ?? null],
    queryFn: ({ signal }) =>
      searchGeocode({
        q: debouncedGeo,
        lat: mapCenter?.lat,
        lng: mapCenter?.lng,
        signal,
      }),
    enabled: debouncedGeo.length >= 2,
    staleTime: 60_000,
    retry: false,
  });

  const createMutation = useMutation({
    mutationFn: async (): Promise<PoiDetail> => {
      const targetPoi =
        createdPoiForRetry ??
        (await createPoi({
          name: name.trim(),
          address: address.trim() || undefined,
          description: description.trim() || undefined,
          latitude: Number(latitude),
          longitude: Number(longitude),
          listIds: selectedListIds,
          isPublic,
        }));

      if (reviewDraft.rating > 0) {
        try {
          await submitReviewWithPhotos(
            targetPoi.id,
            reviewDraft,
            isPublic,
            reviewResumeForRetry,
          );
          setReviewResumeForRetry(null);
        } catch (err) {
          // Preserve created POI and any already-uploaded review/photos so Retry is idempotent.
          setCreatedPoiForRetry(targetPoi);
          if (err instanceof PartialReviewSubmitError) {
            setReviewResumeForRetry(err.resumeState);
          }
          throw err;
        }
      }

      return targetPoi;
    },
    onSuccess: async (created) => {
      await deleteDraft(draftKey);
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
      void queryClient.invalidateQueries({ queryKey: poiKeys.detail(created.id) });
      onCreated(created);
    },
  });

  const handleSelectGeocode = (result: GeocodeResult) => {
    if (!name.trim()) {
      setName(result.label);
    }
    if (result.address) {
      setAddress(result.address);
    }
    setLatitude(result.latitude.toFixed(6));
    setLongitude(result.longitude.toFixed(6));
    setGeoQuery("");
    setDebouncedGeo("");
  };

  const parsedLat = Number(latitude);
  const parsedLng = Number(longitude);
  const hasValidCoords =
    latitude !== "" &&
    longitude !== "" &&
    !Number.isNaN(parsedLat) &&
    !Number.isNaN(parsedLng) &&
    parsedLat >= -90 &&
    parsedLat <= 90 &&
    parsedLng >= -180 &&
    parsedLng <= 180;

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!name.trim() || !hasValidCoords) {
      return;
    }
    createMutation.mutate();
  };

  const apiError = createMutation.error instanceof ApiError ? createMutation.error : null;
  const fallbackError =
    !apiError && createMutation.error instanceof Error ? createMutation.error.message : null;

  const submitLabel = createMutation.isPending
    ? "Saving…"
    : createdPoiForRetry
      ? "Retry review & photos"
      : reviewDraft.rating > 0
        ? `Save place & ${reviewDraft.rating}★ review`
        : "Save place";

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {/* Step 1: Location via Search or Pick on Map */}
      <div className="space-y-2 rounded-xl border border-black/10 p-3 dark:border-white/10">
        <div className="flex items-center justify-between gap-2">
          <label htmlFor={searchId} className="text-xs font-semibold">
            Find location
          </label>
          <button
            type="button"
            onClick={onPickOnMap}
            className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-[var(--accent-brand)]/40 px-2.5 text-xs font-semibold text-[var(--accent-brand)] hover:bg-[var(--accent-brand)]/10"
          >
            <MapPin aria-hidden="true" className="h-3.5 w-3.5" />
            Pick on map
          </button>
        </div>

        <div className="relative">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
          />
          <input
            id={searchId}
            type="text"
            value={geoQuery}
            onChange={(e) => setGeoQuery(e.target.value)}
            placeholder="Where’s the magic happening?"
            className="min-h-11 w-full rounded-lg border border-black/15 bg-transparent py-2 pl-9 pr-9 text-sm dark:border-white/15"
          />
          {geoQuery && (
            <button
              type="button"
              aria-label="Clear location search"
              onClick={() => {
                setGeoQuery("");
                setDebouncedGeo("");
              }}
              className="absolute right-1.5 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
            >
              <X aria-hidden="true" className="h-4 w-4" />
            </button>
          )}
        </div>

        {debouncedGeo.length >= 2 && geocodeQuery.data && geocodeQuery.data.length > 0 && (
          <ul
            aria-label="Place search results"
            className="max-h-40 divide-y divide-black/10 overflow-y-auto rounded-lg border border-black/10 dark:divide-white/10 dark:border-white/10"
          >
            {geocodeQuery.data.map((res, idx) => (
              <li key={`${res.latitude}-${res.longitude}-${idx}`}>
                <button
                  type="button"
                  onClick={() => handleSelectGeocode(res)}
                  className="flex min-h-11 w-full items-start gap-2 px-3 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                >
                  <MapPin aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0 text-[var(--accent-brand)]" />
                  <div className="min-w-0">
                    <p className="truncate text-xs font-semibold">{res.label}</p>
                    {res.address && (
                      <p className="truncate text-[11px] text-[var(--text-muted)]">{res.address}</p>
                    )}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}

        {hasValidCoords ? (
          <p className="text-[11px] text-[var(--vis-public)]">
            Coordinates: {parsedLat.toFixed(5)}, {parsedLng.toFixed(5)}
          </p>
        ) : (
          <p className="text-[11px] text-[var(--text-muted)]">
            Search above or click “Pick on map” to set coordinates.
          </p>
        )}
      </div>

      <div>
        <label htmlFor={nameId} className="block text-xs font-medium">
          Name
        </label>
        <input
          id={nameId}
          type="text"
          required
          maxLength={255}
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="e.g., The Croissant That Changed My Life"
          className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
        />
      </div>

      <div>
        <label htmlFor={addressId} className="block text-xs font-medium">
          Address <span className="font-normal text-[var(--text-muted)]">(optional)</span>
        </label>
        <input
          id={addressId}
          type="text"
          maxLength={500}
          value={address}
          onChange={(e) => setAddress(e.target.value)}
          placeholder="Street, alley, or secret doorway"
          className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
        />
      </div>

      <div>
        <label htmlFor={descId} className="block text-xs font-medium">
          Description <span className="font-normal text-[var(--text-muted)]">(optional)</span>
        </label>
        <textarea
          id={descId}
          rows={3}
          maxLength={10000}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="What’s good here? Worth crossing town for? Spill the tea…"
          className="mt-1 w-full rounded-lg border border-black/15 bg-transparent p-3 text-sm dark:border-white/15"
        />
      </div>

      {assignableLists.length > 0 && (
        <ListMultiSelect
          lists={assignableLists}
          selectedIds={selectedListIds}
          onChange={setSelectedListIds}
        />
      )}

      <ReviewComposer value={reviewDraft} onChange={setReviewDraft} />

      <div className="flex items-center justify-between gap-2 rounded-xl border border-black/10 px-3 py-2.5 dark:border-white/10">
        <div className="flex items-center gap-2 text-xs">
          {isPublic ? (
            <Globe aria-hidden="true" className="h-4 w-4 text-[var(--vis-public)]" />
          ) : (
            <Lock aria-hidden="true" className="h-4 w-4 text-[var(--vis-private)]" />
          )}
          <span className="font-medium">{isPublic ? "Public" : "Friends Only"}</span>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={isPublic}
          aria-label="Toggle public visibility"
          onClick={() => setIsPublic((v) => !v)}
          className="min-h-9 rounded-lg border border-black/15 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
        >
          {isPublic ? "Make Friends Only" : "Make Public"}
        </button>
      </div>

      {(apiError || fallbackError) && (
        <p role="alert" className="text-xs text-[var(--accent-brand)]">
          {createdPoiForRetry
            ? `Place saved! Review/photo upload failed: ${apiError?.message ?? fallbackError}`
            : (apiError?.message ?? fallbackError)}
        </p>
      )}

      <button
        type="submit"
        disabled={createMutation.isPending || !name.trim() || !hasValidCoords}
        className="min-h-11 w-full rounded-lg bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {submitLabel}
      </button>
    </form>
  );
}

export function CreatePoiModal(props: CreatePoiModalProps) {
  const draftKey = `yummy:draft:create-poi:${props.currentUser.id}`;
  const [availableDraft, setAvailableDraft] = useState<CreatePoiDraftData | null>(null);
  const [restoredDraft, setRestoredDraft] = useState<CreatePoiDraftData | null>(null);
  const [restoreSeq, setRestoreSeq] = useState(0);

  useEffect(() => {
    let active = true;
    void loadDraft<CreatePoiDraftData>(draftKey).then((found) => {
      if (active && found) {
        setAvailableDraft(found);
      }
    });
    return () => {
      active = false;
    };
  }, [draftKey]);

  return (
    <Modal title="Add place" closeLabel="Close add place dialog" onClose={props.onClose}>
      {availableDraft && (
        <div
          role="status"
          className="mb-3 flex items-center justify-between gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200"
        >
          <span>Unsaved draft found.</span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => {
                setRestoredDraft(availableDraft);
                setAvailableDraft(null);
                setRestoreSeq((s) => s + 1);
              }}
              className="font-semibold underline"
            >
              Restore
            </button>
            <button
              type="button"
              onClick={() => {
                setAvailableDraft(null);
                void deleteDraft(draftKey);
              }}
              className="text-[var(--text-muted)] underline"
            >
              Discard
            </button>
          </div>
        </div>
      )}

      <CreatePoiForm
        key={restoreSeq}
        {...props}
        draftKey={draftKey}
        initialDraft={restoredDraft}
        onUserInput={() => setAvailableDraft(null)}
      />
    </Modal>
  );
}
