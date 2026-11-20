import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useId, useState, type FormEvent } from "react";
import { ApiError } from "../../api/client";
import { createList, deleteList, listKeys, updateList } from "../../api/lists";
import { poiKeys } from "../../api/pois";
import type { PoiList, PoiListInput, User } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { Modal } from "../ui/Modal";
import { LIST_ICON_OPTIONS, ListIcon } from "./ListIcon";

const COLOR_PRESETS = ["#E11D48", "#4F46E5", "#059669", "#D97706", "#0284C7", "#7C3AED"];

export interface ListManagerModalProps {
  lists: PoiList[];
  currentUser: User;
  isAdmin: boolean;
  initialEditList?: PoiList | null;
  onListDeleted?: (id: string) => void;
  onClose: () => void;
}

export function ListManagerModal({
  lists,
  currentUser,
  isAdmin,
  initialEditList = null,
  onListDeleted,
  onClose,
}: ListManagerModalProps) {
  const queryClient = useQueryClient();
  const nameId = useId();
  const [editingId, setEditingId] = useState<string | null>(initialEditList?.id ?? null);
  const [name, setName] = useState(initialEditList?.name ?? "");
  const [color, setColor] = useState(
    initialEditList ? sanitizeHexColor(initialEditList.color) : COLOR_PRESETS[0],
  );
  const [icon, setIcon] = useState<string>(initialEditList?.icon ?? "bookmark");

  const invalidateAll = () => {
    void queryClient.invalidateQueries({ queryKey: listKeys.all });
    void queryClient.invalidateQueries({ queryKey: poiKeys.all });
  };

  const resetForm = () => {
    setEditingId(null);
    setName("");
    setColor(COLOR_PRESETS[0]);
    setIcon("bookmark");
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PoiListInput) =>
      editingId ? updateList(editingId, payload) : createList(payload),
    onSuccess: () => {
      resetForm();
      invalidateAll();
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteList(id),
    onSuccess: (_data, deletedId) => {
      if (editingId === deletedId) {
        resetForm();
      }
      onListDeleted?.(deletedId);
      invalidateAll();
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

  const startEdit = (list: PoiList) => {
    setEditingId(list.id);
    setName(list.name);
    setColor(sanitizeHexColor(list.color));
    setIcon(list.icon ?? "bookmark");
  };

  const mutationError =
    saveMutation.error instanceof ApiError
      ? saveMutation.error.getFieldError("name") ?? saveMutation.error.message
      : deleteMutation.error instanceof ApiError
        ? deleteMutation.error.message
        : null;

  return (
    <Modal title="Manage Lists" closeLabel="Close list manager" onClose={onClose}>
      <div className="space-y-5">
        <form onSubmit={handleSubmit} className="space-y-3 rounded-xl border border-black/10 p-3.5 dark:border-white/10">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
              {editingId ? "Edit List" : "Create List"}
            </h3>
            {editingId && (
              <button
                type="button"
                onClick={resetForm}
                className="text-xs font-medium text-[var(--text-muted)] underline"
              >
                Cancel edit
              </button>
            )}
          </div>

          <div>
            <label htmlFor={nameId} className="block text-xs font-medium">
              List name
            </label>
            <input
              id={nameId}
              type="text"
              required
              maxLength={80}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Best Bakeries"
              className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 text-sm focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-[var(--text-muted)]">Color:</span>
            {COLOR_PRESETS.map((preset) => (
              <button
                key={preset}
                type="button"
                aria-label={`Select color ${preset}`}
                aria-pressed={color === preset}
                onClick={() => setColor(preset)}
                className={`h-8 w-8 rounded-full border-2 transition-transform ${
                  color === preset ? "scale-110 border-[var(--text-primary)]" : "border-transparent"
                }`}
                style={{ backgroundColor: preset }}
              />
            ))}
          </div>

          <div className="flex flex-wrap items-center gap-1.5">
            <span className="mr-1 text-xs font-medium text-[var(--text-muted)]">Icon:</span>
            {LIST_ICON_OPTIONS.map((opt) => (
              <button
                key={opt.id}
                type="button"
                aria-label={opt.label}
                aria-pressed={icon === opt.id}
                onClick={() => setIcon(opt.id)}
                className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border text-xs ${
                  icon === opt.id
                    ? "border-[var(--accent-brand)] bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]"
                    : "border-black/10 text-[var(--text-muted)] dark:border-white/10"
                }`}
              >
                <ListIcon name={opt.id} className="h-4 w-4" />
              </button>
            ))}
          </div>

          {mutationError && (
            <p role="alert" className="text-xs text-[var(--accent-brand)]">
              {mutationError}
            </p>
          )}

          <button
            type="submit"
            disabled={saveMutation.isPending || name.trim().length === 0}
            className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-lg bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
          >
            <Plus aria-hidden="true" className="h-4 w-4" />
            {saveMutation.isPending
              ? "Saving..."
              : editingId
                ? "Save Changes"
                : "Create List"}
          </button>
        </form>

        <ul aria-label="Existing lists" className="divide-y divide-black/10 dark:divide-white/10">
          {lists.map((list) => {
            const canModify = isAdmin || list.ownerId === currentUser.id;
            return (
              <li key={list.id} className="flex items-center justify-between gap-2 py-2.5">
                <div className="flex min-w-0 items-center gap-2.5">
                  <span
                    aria-hidden="true"
                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white"
                    style={{ backgroundColor: sanitizeHexColor(list.color) }}
                  >
                    <ListIcon name={list.icon} className="h-3.5 w-3.5" />
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{list.name}</p>
                    <p className="text-xs text-[var(--text-muted)]">by {list.ownerName}</p>
                  </div>
                </div>

                {canModify && (
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => startEdit(list)}
                      aria-label={`Edit list ${list.name}`}
                      className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
                    >
                      <Pencil aria-hidden="true" className="h-4 w-4" />
                    </button>
                    <button
                      type="button"
                      disabled={deleteMutation.isPending}
                      onClick={() => deleteMutation.mutate(list.id)}
                      aria-label={`Delete list ${list.name}`}
                      className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-[var(--accent-brand)] hover:bg-[var(--accent-brand)]/10"
                    >
                      <Trash2 aria-hidden="true" className="h-4 w-4" />
                    </button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    </Modal>
  );
}
