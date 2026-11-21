import { MapPin } from "lucide-react";
import { useEffect, useMemo, useRef } from "react";
import type { PoiSummary } from "../../api/types";
import { SpotCard } from "../pois/SpotCard";
import { ScopeSegmentedControl, type ScopeOption } from "../ui/ScopeSegmentedControl";

export type SpotsScope = "area" | "all" | "mine";

export interface SpotsTabPanelProps {
  pois: PoiSummary[];
  isLoading: boolean;
  selectedPoiId: string | null;
  onSelectPoi: (poi: PoiSummary) => void;
  scope: SpotsScope;
  onScopeChange: (next: SpotsScope) => void;
  isAuthenticated: boolean;
  hasMore: boolean;
  isLoadingMore: boolean;
  onLoadMore: () => void;
  pinDropMode: boolean;
  droppedCoords: { lat: number; lng: number } | null;
  onCancelPinDrop: () => void;
}

/**
 * Displays POI cards scoped to the current map area, all spots, or user-created spots,
 * with infinite scroll for global scopes and pin-drop status.
 */
export function SpotsTabPanel({
  pois,
  isLoading,
  selectedPoiId,
  onSelectPoi,
  scope,
  onScopeChange,
  isAuthenticated,
  hasMore,
  isLoadingMore,
  onLoadMore,
  pinDropMode,
  droppedCoords,
  onCancelPinDrop,
}: SpotsTabPanelProps) {
  const sentinelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (scope === "area" || !hasMore || isLoadingMore) {
      return;
    }
    if (typeof IntersectionObserver === "undefined" || !sentinelRef.current) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          onLoadMore();
        }
      },
      { rootMargin: "120px" },
    );
    observer.observe(sentinelRef.current);
    return () => observer.disconnect();
  }, [scope, hasMore, isLoadingMore, onLoadMore]);

  const scopeOptions: ScopeOption<SpotsScope>[] = useMemo(() => {
    const opts: ScopeOption<SpotsScope>[] = [
      {
        value: "area",
        label: "In map area",
        icon: <MapPin aria-hidden="true" className="h-3.5 w-3.5" />,
      },
      { value: "all", label: "All" },
    ];
    if (isAuthenticated) {
      opts.push({ value: "mine", label: "Mine" });
    }
    return opts;
  }, [isAuthenticated]);

  return (
    <div className="space-y-3">
      <ScopeSegmentedControl
        ariaLabel="Spot filters"
        options={scopeOptions}
        value={scope}
        onChange={onScopeChange}
      />

      {pinDropMode && (
        <div
          role="status"
          className="flex items-center justify-between gap-2 rounded-xl border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 px-3 py-2 text-xs"
        >
          <span>Tap anywhere on the map to drop your pin…</span>
          <button type="button" onClick={onCancelPinDrop} className="font-semibold underline">
            Cancel
          </button>
        </div>
      )}

      {droppedCoords && !pinDropMode && (
        <div className="rounded-xl border border-black/10 px-3 py-2 text-xs text-[var(--text-muted)] dark:border-white/10">
          Selected pin: {droppedCoords.lat.toFixed(5)}, {droppedCoords.lng.toFixed(5)}
        </div>
      )}

      {isLoading && pois.length === 0 && (
        <p className="py-6 text-center text-sm text-[var(--text-muted)]">Loading spots…</p>
      )}

      {!isLoading && pois.length === 0 && (
        <p className="rounded-xl border border-dashed border-black/15 py-6 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
          {scope === "mine"
            ? "You haven’t added any spots yet."
            : "No spots match the current view or filters."}
        </p>
      )}

      <ul aria-label="Places" className="space-y-2">
        {pois.map((poi) => (
          <SpotCard
            key={poi.id}
            poi={poi}
            isSelected={poi.id === selectedPoiId}
            onSelect={onSelectPoi}
          />
        ))}
      </ul>

      {scope !== "area" && hasMore && (
        <div ref={sentinelRef} className="pt-1 text-center">
          <button
            type="button"
            disabled={isLoadingMore}
            onClick={onLoadMore}
            className="min-h-10 rounded-lg border border-black/15 px-4 text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-primary)] disabled:opacity-50 dark:border-white/15"
          >
            {isLoadingMore ? "Loading more…" : "Load more"}
          </button>
        </div>
      )}
    </div>
  );
}
