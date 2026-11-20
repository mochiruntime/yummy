import { Check, X } from "lucide-react";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import type { PoiList } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "./ListIcon";

export interface ListMultiSelectProps {
  lists: PoiList[];
  selectedIds: string[];
  onChange: (nextIds: string[]) => void;
  label?: string;
}

/**
 * Collapsible chip-in-input multi-select combobox for assigning a place to owned lists.
 */
export function ListMultiSelect({
  lists,
  selectedIds,
  onChange,
  label = "Add to your lists",
}: ListMultiSelectProps) {
  const inputId = useId();
  const listboxId = useId();
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const [open, setOpen] = useState(false);
  const [filterText, setFilterText] = useState("");

  useEffect(() => {
    const handleOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handleOutside);
    return () => document.removeEventListener("mousedown", handleOutside);
  }, []);

  const selectedLists = useMemo(
    () => lists.filter((list) => selectedIds.includes(list.id)),
    [lists, selectedIds],
  );

  const filteredLists = useMemo(() => {
    const q = filterText.trim().toLowerCase();
    if (!q) {
      return lists;
    }
    return lists.filter((list) => list.name.toLowerCase().includes(q));
  }, [lists, filterText]);

  const toggleList = (id: string) => {
    const next = selectedIds.includes(id)
      ? selectedIds.filter((item) => item !== id)
      : [...selectedIds, id];
    onChange(next);
  };

  return (
    <div
      ref={containerRef}
      onBlur={(e) => {
        if (!containerRef.current?.contains(e.relatedTarget as Node | null)) {
          setOpen(false);
        }
      }}
      className="relative"
    >
      <label htmlFor={inputId} className="block text-xs font-medium">
        {label}
      </label>

      <div
        onClick={() => {
          setOpen(true);
          inputRef.current?.focus();
        }}
        className="mt-1 flex min-h-11 flex-wrap items-center gap-1.5 rounded-lg border border-black/15 bg-transparent px-2.5 py-1.5 focus-within:border-[var(--accent-brand)] dark:border-white/15"
      >
        {selectedLists.map((list) => {
          const color = sanitizeHexColor(list.color);
          return (
            <span
              key={list.id}
              className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold text-white"
              style={{ backgroundColor: color }}
            >
              <ListIcon name={list.icon} className="h-3.5 w-3.5" />
              <span>{list.name}</span>
              <button
                type="button"
                aria-label={`Remove ${list.name}`}
                onClick={(e) => {
                  e.stopPropagation();
                  toggleList(list.id);
                }}
                className="inline-flex h-4 w-4 items-center justify-center rounded-full bg-white/25 hover:bg-white/40"
              >
                <X aria-hidden="true" className="h-3 w-3" />
              </button>
            </span>
          );
        })}

        <input
          ref={inputRef}
          id={inputId}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls={listboxId}
          aria-autocomplete="list"
          value={filterText}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setFilterText(e.target.value);
            setOpen(true);
          }}
          onKeyDown={(e) => {
            if (e.key === "Backspace" && filterText === "" && selectedIds.length > 0) {
              onChange(selectedIds.slice(0, -1));
            } else if (e.key === "Escape" && open) {
              e.preventDefault();
              e.stopPropagation();
              setOpen(false);
            }
          }}
          placeholder={selectedLists.length === 0 ? "Add or filter lists…" : "Filter lists…"}
          className="min-w-[110px] flex-1 bg-transparent py-1 text-sm outline-none placeholder:text-[var(--text-muted)]"
        />
      </div>

      {open && (
        <ul
          id={listboxId}
          role="listbox"
          aria-multiselectable="true"
          aria-label={label}
          className="absolute left-0 right-0 z-30 mt-1 max-h-40 overflow-y-auto rounded-xl border border-black/15 bg-[var(--surface-primary)] p-1 shadow-lg dark:border-white/15"
        >
          {filteredLists.length === 0 ? (
            <li className="px-3 py-2 text-xs text-[var(--text-muted)]">No matching lists</li>
          ) : (
            filteredLists.map((list) => {
              const selected = selectedIds.includes(list.id);
              const color = sanitizeHexColor(list.color);
              return (
                  <li
                  key={list.id}
                  role="option"
                  aria-selected={selected}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => {
                    toggleList(list.id);
                    inputRef.current?.focus();
                  }}
                  className={`flex min-h-10 cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-xs transition-colors ${
                    selected
                      ? "bg-[var(--accent-brand)]/10 font-semibold text-[var(--text-primary)]"
                      : "hover:bg-black/5 dark:hover:bg-white/5"
                  }`}
                >
                  <span className="flex min-w-0 items-center gap-2">
                    <span
                      aria-hidden="true"
                      className="h-2.5 w-2.5 shrink-0 rounded-full"
                      style={{ backgroundColor: color }}
                    />
                    <ListIcon name={list.icon} className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{list.name}</span>
                  </span>
                  {selected && (
                    <Check aria-hidden="true" className="h-3.5 w-3.5 shrink-0 text-[var(--accent-brand)]" />
                  )}
                </li>
              );
            })
          )}
        </ul>
      )}
    </div>
  );
}
