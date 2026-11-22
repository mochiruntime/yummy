import { MapPin, Pencil, Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { Link } from "wouter";
import type { PoiList, PoiSummary, User } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ScopeSegmentedControl, type ScopeOption } from "../ui/ScopeSegmentedControl";
import { ListIcon } from "./ListIcon";

export interface ListsTabPanelProps {
  lists: PoiList[];
  visiblePois: PoiSummary[];
  currentUser: User | null;
  isAdmin: boolean;
  onCreateList: () => void;
  onEditList: (list: PoiList) => void;
}

type ScopeFilter = "all" | "mine" | "area";

/**
 * Compact directory layout for browsing, scope-filtering, opening,
 * and managing curated POI lists.
 */
export function ListsTabPanel({
  lists,
  visiblePois,
  currentUser,
  isAdmin,
  onCreateList,
  onEditList,
}: ListsTabPanelProps) {
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
    return lists.filter((list) => {
      if (scope === "mine" && currentUser && list.ownerId !== currentUser.id) {
        return false;
      }
      if (scope === "area" && (visibleCountByList.get(list.id) ?? 0) === 0) {
        return false;
      }
      return true;
    });
  }, [lists, scope, currentUser, visibleCountByList]);

  const scopeOptions: ScopeOption<ScopeFilter>[] = useMemo(() => {
    const opts: ScopeOption<ScopeFilter>[] = [{ value: "all", label: "All" }];
    if (currentUser) {
      opts.push({ value: "mine", label: "Mine" });
    }
    opts.push({
      value: "area",
      label: "In map area",
      icon: <MapPin aria-hidden="true" className="h-3.5 w-3.5" />,
    });
    return opts;
  }, [currentUser]);

  return (
    <div className="space-y-3">
      <ScopeSegmentedControl
        ariaLabel="List filters"
        options={scopeOptions}
        value={scope}
        onChange={setScope}
      />

      {currentUser && (
        <button
          type="button"
          onClick={onCreateList}
          aria-label="Add list"
          className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-black/20 px-3 text-xs font-semibold text-[var(--text-primary)] hover:border-[var(--accent-brand)] hover:text-[var(--accent-brand)] dark:border-white/20"
        >
          <Plus aria-hidden="true" className="h-4 w-4" />
          New list
        </button>
      )}

      {filteredLists.length === 0 ? (
        <p className="rounded-xl border border-dashed border-black/15 py-6 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
          No lists match your filters.
        </p>
      ) : (
        <ul aria-label="Curated lists" className="divide-y divide-black/10 dark:divide-white/10">
          {filteredLists.map((list) => {
            const canModify = Boolean(currentUser && (isAdmin || list.ownerId === currentUser.id));
            const color = sanitizeHexColor(list.color);
            const countInView = visibleCountByList.get(list.id) ?? 0;

            return (
              <li key={list.id} className="flex items-center justify-between gap-2 py-2">
                <Link
                  href={`/lists/${list.id}`}
                  aria-label={`Open ${list.name}`}
                  className="flex min-h-11 min-w-0 flex-1 items-center gap-2.5 rounded-lg px-1.5 transition-colors hover:bg-black/5 dark:hover:bg-white/5"
                >
                  <span
                    aria-hidden="true"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base text-white"
                    style={{ backgroundColor: color }}
                  >
                    <ListIcon name={list.icon} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{list.name}</span>
                    <span className="block truncate text-xs text-[var(--text-muted)]">
                      {countInView} {countInView === 1 ? "spot" : "spots"} · {list.ownerName}
                    </span>
                  </span>
                </Link>

                {canModify && (
                  <button
                    type="button"
                    onClick={() => onEditList(list)}
                    aria-label={`Edit ${list.name}`}
                    className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
                  >
                    <Pencil aria-hidden="true" className="h-4 w-4" />
                  </button>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
