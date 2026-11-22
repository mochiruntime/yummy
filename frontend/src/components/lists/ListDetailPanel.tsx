import { ArrowLeft } from "lucide-react";
import { Link } from "wouter";
import type { PoiList, PoiSummary } from "../../api/types";
import { SpotCard } from "../pois/SpotCard";
import { SharedListBanner } from "./SharedListBanner";

export interface ListDetailPanelProps {
  sharedList: PoiList | undefined;
  isListsLoading: boolean;
  isValidSharedListId: boolean;
  pois: PoiSummary[];
  isPoisLoading: boolean;
  selectedPoiId: string | null;
  isAuthenticated: boolean;
  canEdit?: boolean;
  onEditList?: (list: PoiList) => void;
  onSelectPoi: (poi: PoiSummary) => void;
}

/**
 * Opened list view rendered inside the Lists tab when viewing `/lists/:id`.
 */
export function ListDetailPanel({
  sharedList,
  isListsLoading,
  isValidSharedListId,
  pois,
  isPoisLoading,
  selectedPoiId,
  isAuthenticated,
  canEdit = false,
  onEditList,
  onSelectPoi,
}: ListDetailPanelProps) {
  return (
    <div className="space-y-3">
      <Link
        href="/"
        className="inline-flex min-h-11 items-center gap-1.5 text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-primary)]"
      >
        <ArrowLeft aria-hidden="true" className="h-4 w-4" />
        All lists
      </Link>

      <SharedListBanner
        list={isValidSharedListId ? sharedList : undefined}
        isLoading={isValidSharedListId && isListsLoading}
        spotCount={pois.length}
        isAuthenticated={isAuthenticated}
        canEdit={canEdit}
        onEdit={onEditList}
      />

      {isPoisLoading && pois.length === 0 && (
        <p className="py-6 text-center text-sm text-[var(--text-muted)]">Loading spots…</p>
      )}

      {!isPoisLoading && sharedList && pois.length === 0 && (
        <p className="rounded-xl border border-dashed border-black/15 py-6 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
          No visible spots in this list.
        </p>
      )}

      <ul aria-label="List places" className="space-y-2">
        {pois.map((poi) => (
          <SpotCard
            key={poi.id}
            poi={poi}
            isSelected={poi.id === selectedPoiId}
            onSelect={onSelectPoi}
          />
        ))}
      </ul>
    </div>
  );
}
