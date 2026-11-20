import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RotateCcw } from "lucide-react";
import { ApiError } from "../../api/client";
import { fetchPoiRevisions, poiKeys, revertPoiRevision } from "../../api/pois";
import type { PoiDetail, PoiRevision } from "../../api/types";
import { Modal } from "../ui/Modal";

export interface PoiRevisionsModalProps {
  poiId: string;
  poiName: string;
  currentVersion: number;
  canManage: boolean;
  onReverted: (updated: PoiDetail) => void;
  onClose: () => void;
}

function formatChangedKeys(revision: PoiRevision): string {
  const changed = revision.changedFields as { diff?: Record<string, unknown> } | undefined;
  const diff = changed?.diff;
  if (!diff || typeof diff !== "object") {
    return "Initial version";
  }
  const keys = Object.keys(diff);
  if (keys.length === 0) {
    return "Snapshot recorded";
  }
  return `Changed: ${keys.join(", ")}`;
}

export function PoiRevisionsModal({
  poiId,
  poiName,
  currentVersion,
  canManage,
  onReverted,
  onClose,
}: PoiRevisionsModalProps) {
  const queryClient = useQueryClient();

  const revisionsQuery = useQuery({
    queryKey: poiKeys.revisions(poiId),
    queryFn: ({ signal }) => fetchPoiRevisions(poiId, signal),
  });

  const revertMutation = useMutation({
    mutationFn: (revisionNumber: number) => revertPoiRevision(poiId, revisionNumber),
    onSuccess: (updated) => {
      queryClient.setQueryData(poiKeys.detail(poiId), updated);
      void queryClient.invalidateQueries({ queryKey: poiKeys.all });
      void queryClient.invalidateQueries({ queryKey: poiKeys.revisions(poiId) });
      onReverted(updated);
    },
  });

  const revertError =
    revertMutation.error instanceof ApiError ? revertMutation.error.message : null;

  return (
    <Modal title={`History · ${poiName}`} closeLabel="Close revision history" onClose={onClose}>
      <div className="space-y-3">
        {revisionsQuery.isPending && (
          <p className="text-sm text-[var(--text-muted)]">Loading revision history…</p>
        )}

        {revisionsQuery.isError && (
          <p role="alert" className="text-sm text-[var(--accent-brand)]">
            Unable to load revision history.
          </p>
        )}

        {revertError && (
          <p role="alert" className="text-xs text-[var(--accent-brand)]">
            {revertError}
          </p>
        )}

        {revisionsQuery.data && (
          <ul aria-label="Revision history" className="divide-y divide-black/10 dark:divide-white/10">
            {revisionsQuery.data.map((rev) => {
              const isCurrent = rev.revisionNumber === currentVersion;
              return (
                <li key={rev.revisionNumber} className="flex items-start justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-xs font-bold">v{rev.revisionNumber}</span>
                      {isCurrent && (
                        <span className="rounded-full bg-[var(--vis-public)]/15 px-2 py-0.5 text-[11px] font-medium text-[var(--vis-public)]">
                          Current
                        </span>
                      )}
                    </div>
                    <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                      {rev.editorName || "Member"} ·{" "}
                      {new Date(rev.createdAt).toLocaleString()}
                    </p>
                    <p className="mt-1 text-xs text-[var(--text-primary)]">
                      {formatChangedKeys(rev)}
                    </p>
                  </div>

                  {canManage && !isCurrent && (
                    <button
                      type="button"
                      disabled={revertMutation.isPending}
                      onClick={() => revertMutation.mutate(rev.revisionNumber)}
                      aria-label={`Revert to v${rev.revisionNumber}`}
                      className="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg border border-black/15 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
                    >
                      <RotateCcw aria-hidden="true" className="h-3.5 w-3.5" />
                      Revert to v{rev.revisionNumber}
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Modal>
  );
}
