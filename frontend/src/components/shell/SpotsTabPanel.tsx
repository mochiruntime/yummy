import { Globe, Lock } from "lucide-react";
import type { PoiList, PoiSummary } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "../lists/ListIcon";
import { SharedListBanner } from "../lists/SharedListBanner";

export interface SpotsTabPanelProps {
  pois: PoiSummary[];
  isLoading: boolean;
  selectedPoiId: string | null;
  onSelectPoi: (poi: PoiSummary) => void;
  isSharedListRoute: boolean;
  isValidSharedListId: boolean;
  sharedList: PoiList | undefined;
  isListsLoading: boolean;
  isAuthenticated: boolean;
  pinDropMode: boolean;
  droppedCoords: { lat: number; lng: number } | null;
  onCancelPinDrop: () => void;
}

/**
 * Displays filtered POI cards, optional shared-list banner, and pin-drop status.
 */
export function SpotsTabPanel({
  pois,
  isLoading,
  selectedPoiId,
  onSelectPoi,
  isSharedListRoute,
  isValidSharedListId,
  sharedList,
  isListsLoading,
  isAuthenticated,
  pinDropMode,
  droppedCoords,
  onCancelPinDrop,
}: SpotsTabPanelProps) {
  return (
    <div className="space-y-3">
      {isSharedListRoute && (
        <SharedListBanner
          list={isValidSharedListId ? sharedList : undefined}
          isLoading={isValidSharedListId && isListsLoading}
          spotCount={pois.length}
          isAuthenticated={isAuthenticated}
        />
      )}

      {pinDropMode && (
        <div
          role="status"
          className="flex items-center justify-between gap-2 rounded-xl border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 px-3 py-2.5 text-xs"
        >
          <span>
            {droppedCoords
              ? `Pin dropped at ${droppedCoords.lat.toFixed(5)}, ${droppedCoords.lng.toFixed(5)}`
              : "Tap anywhere on the map to drop a pin."}
          </span>
          <button
            type="button"
            onClick={onCancelPinDrop}
            className="min-h-8 shrink-0 font-semibold text-[var(--accent-brand)] underline"
          >
            Cancel
          </button>
        </div>
      )}

      {isLoading && pois.length === 0 && (
        <p className="py-6 text-center text-sm text-[var(--text-muted)]">Loading spots…</p>
      )}

      {!isLoading && pois.length === 0 && (
        <p className="rounded-xl border border-dashed border-black/15 py-6 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
          No spots match the current view or filters.
        </p>
      )}

      <ul aria-label="Places" className="space-y-2">
        {pois.map((poi) => {
          const isSelected = poi.id === selectedPoiId;
          return (
            <li key={poi.id}>
              <button
                type="button"
                onClick={() => onSelectPoi(poi)}
                className={`w-full rounded-xl border p-3 text-left transition-colors ${
                  isSelected
                    ? "border-[var(--accent-brand)] bg-[var(--accent-brand)]/5"
                    : "border-black/10 hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/5"
                }`}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="text-sm font-semibold">{poi.name}</span>
                  <span
                    className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                      poi.isPublic
                        ? "bg-[var(--vis-public)]/15 text-[var(--vis-public)]"
                        : "bg-[var(--vis-private)]/15 text-[var(--vis-private)]"
                    }`}
                  >
                    {poi.isPublic ? (
                      <>
                        <Globe aria-hidden="true" className="h-3 w-3" />
                        Public
                      </>
                    ) : (
                      <>
                        <Lock aria-hidden="true" className="h-3 w-3" />
                        Friends Only
                      </>
                    )}
                  </span>
                </div>

                {poi.address && (
                  <p className="mt-1 truncate text-xs text-[var(--text-muted)]">{poi.address}</p>
                )}

                {poi.lists.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {poi.lists.map((list) => (
                      <span
                        key={list.id}
                        className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-white"
                        style={{ backgroundColor: sanitizeHexColor(list.color) }}
                      >
                        <ListIcon name={list.icon} className="h-3 w-3" />
                        {list.name}
                      </span>
                    ))}
                  </div>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
