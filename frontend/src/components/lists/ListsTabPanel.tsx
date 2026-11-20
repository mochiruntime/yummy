import { Check, ExternalLink, MapPin, Pencil, Plus, Search } from "lucide-react";
import { useMemo, useState } from "react";
import { Link } from "wouter";
import type { PoiList, PoiSummary, User } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "./ListIcon";

export interface ListsTabPanelProps {
  lists: PoiList[];
  visiblePois: PoiSummary[];
  selectedListIds: string[];
  currentUser: User | null;
  isAdmin: boolean;
  onToggleListFilter: (id: string) => void;
  onCreateList: () => void;
  onEditList: (list: PoiList) => void;
}

type ScopeFilter = "all" | "mine" | "area";

/**
 * Compact directory layout (Option A) for discovering, searching,
 * location-filtering, multi-selecting, and managing curated POI lists.
 */
export function ListsTabPanel({
  lists,
  visiblePois,
  selectedListIds,
  currentUser,
  isAdmin,
  onToggleListFilter,
  onCreateList,
  onEditList,
}: ListsTabPanelProps) {
  const [query, setQuery] = useState("");
  const [scope, setScope] = useState<ScopeFilter>("all");

  const visibleCountByList = useMemo(() => {
    const counts = new Map<string, number>();
    for (const poi of visiblePois) {
      for (const list of poi.lists) {
        counts.set(list.id, (counts.get(list.id) ?? 0) + 1);
      }
    }
    return counts;
  }, [visiblePois]);

  const filteredLists = useMemo(() => {
    const q = query.trim().toLowerCase();
    return lists.filter((list) => {
      if (scope === "mine" && currentUser && list.ownerId !== currentUser.id) {
        return false;
      }
      if (scope === "area" && (visibleCountByList.get(list.id) ?? 0) === 0) {
        return false;
      }
      if (q) {
        return (
          list.name.toLowerCase().includes(q) ||
          list.ownerName.toLowerCase().includes(q)
        );
      }
      return true;
    });
  }, [lists, query, scope, currentUser, visibleCountByList]);

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
          />
          <input
            type="search"
            aria-label="Filter lists"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Filter lists by name or owner…"
            className="min-h-11 w-full rounded-xl border border-black/15 bg-transparent py-2 pl-9 pr-3 text-sm placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
          />
        </div>

        {currentUser && (
          <button
            type="button"
            onClick={onCreateList}
            aria-label="Add list"
            className="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-xl bg-[var(--accent-brand)] px-3 text-xs font-semibold text-white hover:opacity-90"
          >
            <Plus aria-hidden="true" className="h-4 w-4" />
            Add list
          </button>
        )}
      </div>

      {/* Segmented scope control */}
      <div
        role="group"
        aria-label="List filters"
        className={`grid gap-1 rounded-xl bg-black/5 p-1 dark:bg-white/5 ${
          currentUser ? "grid-cols-3" : "grid-cols-2"
        }`}
      >
        <button
          type="button"
          aria-pressed={scope === "all"}
          onClick={() => setScope("all")}
          className={`min-h-11 rounded-lg text-xs font-semibold transition-colors ${
            scope === "all"
              ? "bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-xs"
              : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
          }`}
        >
          All ({lists.length})
        </button>

        {currentUser && (
          <button
            type="button"
            aria-pressed={scope === "mine"}
            onClick={() => setScope("mine")}
            className={`min-h-11 rounded-lg text-xs font-semibold transition-colors ${
              scope === "mine"
                ? "bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-xs"
                : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
            }`}
          >
            Mine
          </button>
        )}

        <button
          type="button"
          aria-pressed={scope === "area"}
          onClick={() => setScope("area")}
          className={`inline-flex min-h-11 items-center justify-center gap-1 rounded-lg text-xs font-semibold transition-colors ${
            scope === "area"
              ? "bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-xs"
              : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
          }`}
        >
          <MapPin aria-hidden="true" className="h-3.5 w-3.5" />
          In map area
        </button>
      </div>

      {filteredLists.length === 0 ? (
        <p className="rounded-xl border border-dashed border-black/15 py-6 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
          No lists match your filters.
        </p>
      ) : (
        <ul aria-label="Curated lists" className="divide-y divide-black/10 dark:divide-white/10">
          {filteredLists.map((list) => {
            const isFiltered = selectedListIds.includes(list.id);
            const canModify = Boolean(currentUser && (isAdmin || list.ownerId === currentUser.id));
            const color = sanitizeHexColor(list.color);
            const countInView = visibleCountByList.get(list.id) ?? 0;

            return (
              <li key={list.id} className="flex items-center justify-between gap-2 py-2.5">
                <div className="flex min-w-0 items-center gap-2.5">
                  <span
                    aria-hidden="true"
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white"
                    style={{ backgroundColor: color }}
                  >
                    <ListIcon name={list.icon} className="h-4 w-4" />
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{list.name}</p>
                    <p className="truncate text-xs text-[var(--text-muted)]">
                      {countInView} {countInView === 1 ? "spot" : "spots"} · {list.ownerName}
                    </p>
                  </div>
                </div>

                <div className="flex shrink-0 items-center gap-1">
                  <button
                    type="button"
                    aria-pressed={isFiltered}
                    aria-label={isFiltered ? `Remove ${list.name} filter` : `Filter by ${list.name}`}
                    onClick={() => onToggleListFilter(list.id)}
                    className={`inline-flex min-h-11 items-center gap-1 rounded-full border px-3 text-xs font-semibold transition-colors ${
                      isFiltered
                        ? "border-transparent text-white"
                        : "border-black/15 hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/5"
                    }`}
                    style={isFiltered ? { backgroundColor: color } : undefined}
                  >
                    {isFiltered ? (
                      <>
                        <Check aria-hidden="true" className="h-3.5 w-3.5" />
                        Active
                      </>
                    ) : (
                      "Filter"
                    )}
                  </button>

                  <Link
                    href={`/lists/${list.id}`}
                    aria-label={`Open ${list.name}`}
                    className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
                  >
                    <ExternalLink aria-hidden="true" className="h-4 w-4" />
                  </Link>

                  {canModify && (
                    <button
                      type="button"
                      onClick={() => onEditList(list)}
                      aria-label={`Edit ${list.name}`}
                      className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
                    >
                      <Pencil aria-hidden="true" className="h-4 w-4" />
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
