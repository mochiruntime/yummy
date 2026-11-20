import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RefreshCw } from "lucide-react";
import { useId, useState, type FormEvent } from "react";
import { ApiError } from "../../api/client";
import { fetchPoiDetail, poiKeys, updatePoi } from "../../api/pois";
import type { PoiDetail } from "../../api/types";
import { Modal } from "../ui/Modal";

export interface EditPoiModalProps {
  detail: PoiDetail;
  onUpdated: (updated: PoiDetail) => void;
  onClose: () => void;
}

function EditPoiForm({
  detail,
  onUpdated,
}: {
  detail: PoiDetail;
  onUpdated: (updated: PoiDetail) => void;
}) {
  const queryClient = useQueryClient();
  const nameId = useId();
  const addressId = useId();
  const descId = useId();
  const latId = useId();
  const lngId = useId();

  const [name, setName] = useState(detail.name);
  const [address, setAddress] = useState(detail.address ?? "");
  const [description, setDescription] = useState(detail.description ?? "");
  const [latitude, setLatitude] = useState(String(detail.latitude));
  const [longitude, setLongitude] = useState(String(detail.longitude));
  const [reloading, setReloading] = useState(false);

  const mutation = useMutation({
    mutationFn: () =>
      updatePoi(detail.id, {
        version: detail.version,
        name: name.trim(),
        address: address.trim(),
        description: description.trim(),
        latitude: Number(latitude),
        longitude: Number(longitude),
      }),
    onSuccess: (updated) => {
      queryClient.setQueryData(poiKeys.detail(detail.id), updated);
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
      void queryClient.invalidateQueries({ queryKey: poiKeys.revisions(detail.id) });
      onUpdated(updated);
    },
  });

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!name.trim()) {
      return;
    }
    mutation.mutate();
  };

  const handleReloadLatest = async () => {
    setReloading(true);
    try {
      await queryClient.refetchQueries({ queryKey: poiKeys.detail(detail.id) });
      mutation.reset();
    } finally {
      setReloading(false);
    }
  };

  const apiError = mutation.error instanceof ApiError ? mutation.error : null;
  const isConflict = apiError?.status === 409;

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-xs text-[var(--text-muted)]">
        Editing version <span className="font-mono font-semibold">v{detail.version}</span>
      </p>

      {isConflict && (
        <div
          role="alert"
          className="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200"
        >
          <p className="font-semibold">
            {apiError?.message ?? "Conflict: this spot was modified by someone else."}
          </p>
          <button
            type="button"
            disabled={reloading}
            onClick={() => void handleReloadLatest()}
            className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--surface-primary)] px-3 font-semibold text-[var(--text-primary)] shadow-xs"
          >
            <RefreshCw aria-hidden="true" className="h-3.5 w-3.5" />
            {reloading ? "Reloading…" : "Reload latest version"}
          </button>
        </div>
      )}

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
          className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
        />
      </div>

      <div>
        <label htmlFor={addressId} className="block text-xs font-medium">
          Address
        </label>
        <input
          id={addressId}
          type="text"
          maxLength={500}
          value={address}
          onChange={(e) => setAddress(e.target.value)}
          className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
        />
      </div>

      <div>
        <label htmlFor={descId} className="block text-xs font-medium">
          Description
        </label>
        <textarea
          id={descId}
          rows={3}
          maxLength={10000}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          className="mt-1 w-full rounded-lg border border-black/15 bg-transparent p-3 text-sm dark:border-white/15"
        />
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label htmlFor={latId} className="block text-xs font-medium">
            Latitude
          </label>
          <input
            id={latId}
            type="number"
            step="any"
            required
            value={latitude}
            onChange={(e) => setLatitude(e.target.value)}
            className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
          />
        </div>
        <div>
          <label htmlFor={lngId} className="block text-xs font-medium">
            Longitude
          </label>
          <input
            id={lngId}
            type="number"
            step="any"
            required
            value={longitude}
            onChange={(e) => setLongitude(e.target.value)}
            className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm dark:border-white/15"
          />
        </div>
      </div>

      {apiError && !isConflict && (
        <p role="alert" className="text-xs text-[var(--accent-brand)]">
          {apiError.message}
        </p>
      )}

      <button
        type="submit"
        disabled={mutation.isPending || !name.trim()}
        className="min-h-11 w-full rounded-lg bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
      >
        {mutation.isPending ? "Saving changes…" : "Save changes"}
      </button>
    </form>
  );
}

export function EditPoiModal({ detail, onUpdated, onClose }: EditPoiModalProps) {
  const detailQuery = useQuery({
    queryKey: poiKeys.detail(detail.id),
    queryFn: ({ signal }) => fetchPoiDetail(detail.id, signal),
    initialData: detail,
  });
  const activeDetail = detailQuery.data;

  return (
    <Modal title={`Edit ${activeDetail.name}`} closeLabel="Close edit dialog" onClose={onClose}>
      {/* Keying by version resets form state cleanly when reloading after a 409 conflict */}
      <EditPoiForm key={activeDetail.version} detail={activeDetail} onUpdated={onUpdated} />
    </Modal>
  );
}
