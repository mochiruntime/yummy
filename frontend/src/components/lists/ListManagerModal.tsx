import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Save, Trash2 } from "lucide-react";
import { useId, useState, type FormEvent } from "react";
import { ApiError } from "../../api/client";
import { createList, deleteList, listKeys, updateList } from "../../api/lists";
import { poiKeys } from "../../api/pois";
import type { PoiList, PoiListInput } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { Modal } from "../ui/Modal";
import { EmojiPicker } from "./EmojiPicker";
import { ListIcon } from "./ListIcon";

const COLOR_PRESETS = [
  "#E11D48", "#EA580C", "#D97706", "#65A30D", "#059669", "#0D9488",
  "#0284C7", "#2563EB", "#4F46E5", "#7C3AED", "#DB2777", "#475569",
] as const;

export interface ListManagerModalProps {
  initialEditList?: PoiList | null;
  onListDeleted?: (id: string) => void;
  onClose: () => void;
}

/**
 * Single-list Create / Edit modal with live badge preview, 12-color swatch presets,
 * offline emojibase-data icon picker, and inline delete confirmation.
 */
export function ListManagerModal({
  initialEditList = null,
  onListDeleted,
  onClose,
}: ListManagerModalProps) {
  const queryClient = useQueryClient();
  const nameId = useId();
  const editingId = initialEditList?.id ?? null;
  const isEditing = editingId !== null;

  const [name, setName] = useState(initialEditList?.name ?? "");
  const [color, setColor] = useState(
    initialEditList ? sanitizeHexColor(initialEditList.color) : COLOR_PRESETS[0],
  );
  const [icon, setIcon] = useState<string>(initialEditList?.icon?.trim() || "📍");
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const invalidateAll = () => {
    void queryClient.invalidateQueries({ queryKey: listKeys.all });
    void queryClient.invalidateQueries({ queryKey: poiKeys.all });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PoiListInput) =>
      editingId ? updateList(editingId, payload) : createList(payload),
    onSuccess: () => {
      invalidateAll();
      onClose();
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteList(id),
    onSuccess: (_data, deletedId) => {
      invalidateAll();
      onListDeleted?.(deletedId);
      onClose();
    },
  });

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) {
      return;
    }
    saveMutation.mutate({ name: trimmed, color, icon });
  };

  const mutationError =
    saveMutation.error instanceof ApiError
      ? saveMutation.error.getFieldError("name") ?? saveMutation.error.message
      : deleteMutation.error instanceof ApiError
        ? deleteMutation.error.message
        : null;

  return (
    <Modal
      title={isEditing ? "Edit List" : "Create List"}
      closeLabel="Close dialog"
      onClose={onClose}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="flex items-center gap-3">
          <span
            aria-hidden="true"
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-2xl text-white shadow-sm"
            style={{ backgroundColor: color }}
          >
            <ListIcon name={icon} />
          </span>
          <div className="min-w-0 flex-1">
            <label htmlFor={nameId} className="block text-xs font-medium text-[var(--text-muted)]">
              List name
            </label>
            <input
              id={nameId}
              type="text"
              required
              maxLength={80}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Late-Night Tacos & Gelato"
              className="mt-1 min-h-10 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <span className="block text-xs font-medium text-[var(--text-muted)]">Color</span>
          <div className="flex flex-wrap items-center gap-1.5">
            {COLOR_PRESETS.map((preset) => (
              <button
                key={preset}
                type="button"
                aria-label={`Select color ${preset}`}
                aria-pressed={color === preset}
                onClick={() => setColor(preset)}
                className={`h-[26px] w-[26px] shrink-0 rounded-full border-2 transition-transform ${
                  color === preset ? "scale-110 border-[var(--text-primary)]" : "border-transparent"
                }`}
                style={{ backgroundColor: preset }}
              />
            ))}
          </div>
        </div>

        <EmojiPicker value={icon} onChange={setIcon} />

        {mutationError && (
          <p role="alert" className="text-xs text-[var(--accent-brand)]">
            {mutationError}
          </p>
        )}

        <div className="flex items-center gap-2 pt-1">
          {isEditing && confirmingDelete ? (
            <div className="flex min-h-11 w-full items-center justify-between gap-2 rounded-lg border border-[var(--accent-brand)]/25 bg-[var(--accent-brand)]/[0.05] px-3 text-xs">
              <span className="font-medium text-[var(--text-primary)]">
                Delete this list? Spots stay on the map.
              </span>
              <div className="flex shrink-0 gap-1.5">
                <button
                  type="button"
                  disabled={deleteMutation.isPending}
                  onClick={() => setConfirmingDelete(false)}
                  className="min-h-8 rounded-md border border-black/15 bg-[var(--surface-primary)] px-2.5 font-medium hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={deleteMutation.isPending}
                  onClick={() => deleteMutation.mutate(editingId)}
                  className="min-h-8 rounded-md bg-[var(--accent-brand)] px-2.5 font-semibold text-white hover:opacity-90 disabled:opacity-50"
                >
                  {deleteMutation.isPending ? "Deleting..." : "Confirm"}
                </button>
              </div>
            </div>
          ) : (
            <>
              {isEditing && (
                <button
                  type="button"
                  onClick={() => setConfirmingDelete(true)}
                  aria-label="Delete list"
                  className="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-black/15 px-3 text-xs font-semibold text-[var(--accent-brand)] hover:bg-[var(--accent-brand)]/10 dark:border-white/15"
                >
                  <Trash2 aria-hidden="true" className="h-4 w-4 shrink-0" />
                  <span>Delete</span>
                </button>
              )}
              <button
                type="submit"
                disabled={saveMutation.isPending || name.trim().length === 0}
                className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
              >
                {isEditing ? (
                  <Save aria-hidden="true" className="h-4 w-4" />
                ) : (
                  <Plus aria-hidden="true" className="h-4 w-4" />
                )}
                {saveMutation.isPending
                  ? "Saving..."
                  : isEditing
                    ? "Save changes"
                    : "Create list"}
              </button>
            </>
          )}
        </div>
      </form>
    </Modal>
  );
}
