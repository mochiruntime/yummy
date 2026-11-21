import { Globe, Lock } from "lucide-react";
import { useEffect, useRef } from "react";
import type { PoiSummary } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "../lists/ListIcon";

export interface SpotCardProps {
  poi: PoiSummary;
  isSelected: boolean;
  onSelect: (poi: PoiSummary) => void;
}

/**
 * Compact POI summary card used in both the Spots tab and opened List detail view.
 */
export function SpotCard({ poi, isSelected, onSelect }: SpotCardProps) {
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (isSelected && buttonRef.current && typeof buttonRef.current.scrollIntoView === "function") {
      buttonRef.current.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }, [isSelected]);

  return (
    <li>
      <button
        ref={buttonRef}
        type="button"
        aria-current={isSelected ? "true" : undefined}
        onClick={() => onSelect(poi)}
        className={`w-full rounded-xl border p-3 text-left transition-colors ${
          isSelected
            ? "border-[var(--accent-brand)] bg-[var(--accent-brand)]/5 ring-1 ring-[var(--accent-brand)]/30"
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
}
