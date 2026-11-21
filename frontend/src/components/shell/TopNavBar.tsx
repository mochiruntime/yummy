import { LogIn, LogOut, MapPin, Search, Users, X } from "lucide-react";
import { useEffect, useId, useRef, useState } from "react";
import { Link } from "wouter";
import type { GeocodeResult, PoiList, PoiSummary, User } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "../lists/ListIcon";

export interface TopNavBarProps {
  user: User | null;
  isAdmin: boolean;
  searchText: string;
  onSearchTextChange: (value: string) => void;
  onClearSearch: () => void;
  spotSuggestions?: PoiSummary[];
  onSelectSpotSuggestion?: (poi: PoiSummary) => void;
  listSuggestions?: PoiList[];
  onSelectListSuggestion?: (list: PoiList) => void;
  geocodeSuggestions: GeocodeResult[];
  showSearchDropdown: boolean;
  geocodeRateLimited?: boolean;
  onSelectGeocode: (result: GeocodeResult) => void;
  canUseGoogleMaps: boolean;
  renderer: "maplibre" | "google";
  onToggleRenderer: () => void;
  onOpenSignIn: () => void;
  onSignOut: () => void;
}

/**
 * Unified top navigation bar with brand link, centered heterogeneous search
 * (spots, lists, and address autocomplete), conditional map renderer toggle, and account controls.
 */
export function TopNavBar({
  user,
  isAdmin,
  searchText,
  onSearchTextChange,
  onClearSearch,
  spotSuggestions = [],
  onSelectSpotSuggestion,
  listSuggestions = [],
  onSelectListSuggestion,
  geocodeSuggestions,
  showSearchDropdown,
  geocodeRateLimited = false,
  onSelectGeocode,
  canUseGoogleMaps,
  renderer,
  onToggleRenderer,
  onOpenSignIn,
  onSignOut,
}: TopNavBarProps) {
  const dropdownId = useId();
  const searchContainerRef = useRef<HTMLDivElement>(null);
  const [dropdownDismissed, setDropdownDismissed] = useState(false);

  const hasAnyResults =
    spotSuggestions.length > 0 ||
    listSuggestions.length > 0 ||
    geocodeSuggestions.length > 0 ||
    geocodeRateLimited;
  const isDropdownVisible = showSearchDropdown && !dropdownDismissed && hasAnyResults;

  useEffect(() => {
    const handlePointerDown = (e: PointerEvent) => {
      if (
        searchContainerRef.current &&
        e.target instanceof Node &&
        !searchContainerRef.current.contains(e.target)
      ) {
        setDropdownDismissed(true);
      }
    };
    document.addEventListener("pointerdown", handlePointerDown);
    return () => document.removeEventListener("pointerdown", handlePointerDown);
  }, []);

  return (
    <header className="relative z-30 flex flex-wrap items-center justify-between gap-2 border-b border-black/10 bg-[var(--surface-overlay)] px-4 py-2 backdrop-blur-md dark:border-white/10">
      <Link href="/" className="inline-flex min-h-11 items-center gap-2 font-bold tracking-tight">
        <MapPin aria-hidden="true" className="h-5 w-5 text-[var(--accent-brand)]" />
        <span>yummy</span>
      </Link>

      {/* Centered Search Bar (wraps onto its own row on mobile, centered on md+) */}
      <div
        ref={searchContainerRef}
        onKeyDown={(e) => {
          if (e.key === "Escape" && isDropdownVisible) {
            e.stopPropagation();
            setDropdownDismissed(true);
          }
        }}
        className="relative order-3 w-full md:order-2 md:mx-4 md:max-w-md md:flex-1"
      >
        <Search
          aria-hidden="true"
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
        />
        <input
          type="search"
          aria-label="Search places"
          aria-expanded={isDropdownVisible}
          aria-controls={isDropdownVisible ? dropdownId : undefined}
          value={searchText}
          onFocus={() => setDropdownDismissed(false)}
          onChange={(e) => {
            setDropdownDismissed(false);
            onSearchTextChange(e.target.value);
          }}
          placeholder="Search spots, lists, or addresses…"
          className="min-h-11 w-full rounded-xl border border-black/15 bg-[var(--surface-primary)] py-2 pl-9 pr-9 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
        />
        {searchText && (
          <button
            type="button"
            onClick={() => {
              setDropdownDismissed(false);
              onClearSearch();
            }}
            aria-label="Clear search"
            className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)]"
          >
            <X aria-hidden="true" className="h-4 w-4" />
          </button>
        )}

        {isDropdownVisible && (
          <div
            id={dropdownId}
            role="region"
            aria-label="Search results"
            className="absolute left-0 right-0 top-12 z-40 max-h-80 space-y-2 overflow-y-auto rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-2 shadow-xl dark:border-white/15"
          >
            {spotSuggestions.length > 0 && (
              <div>
                <p className="px-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">
                  Spots
                </p>
                <ul aria-label="Matching spots" className="space-y-0.5">
                  {spotSuggestions.map((poi) => (
                    <li key={poi.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setDropdownDismissed(true);
                          onSelectSpotSuggestion?.(poi);
                        }}
                        className="flex min-h-11 w-full items-start gap-2.5 rounded-xl px-2.5 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                      >
                        <MapPin
                          aria-hidden="true"
                          className="mt-0.5 h-4 w-4 shrink-0 text-[var(--accent-brand)]"
                        />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-xs font-semibold text-[var(--text-primary)]">
                            {poi.name}
                          </p>
                          {poi.address && (
                            <p className="truncate text-[11px] text-[var(--text-muted)]">
                              {poi.address}
                            </p>
                          )}
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {listSuggestions.length > 0 && (
              <div>
                <p className="px-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">
                  Lists
                </p>
                <ul aria-label="Matching lists" className="space-y-0.5">
                  {listSuggestions.map((list) => (
                    <li key={list.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setDropdownDismissed(true);
                          onSelectListSuggestion?.(list);
                        }}
                        className="flex min-h-11 w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                      >
                        <span
                          aria-hidden="true"
                          className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-white"
                          style={{ backgroundColor: sanitizeHexColor(list.color) }}
                        >
                          <ListIcon name={list.icon} className="h-3.5 w-3.5" />
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-xs font-semibold text-[var(--text-primary)]">
                            {list.name}
                          </p>
                          <p className="truncate text-[11px] text-[var(--text-muted)]">
                            by {list.ownerName}
                          </p>
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {geocodeRateLimited && (
              <div
                role="status"
                className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"
              >
                Too many searches — please wait a moment.
              </div>
            )}

            {!geocodeRateLimited && geocodeSuggestions.length > 0 && (
              <div>
                <p className="px-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">
                  Addresses
                </p>
                <ul aria-label="Address suggestions" className="space-y-0.5">
                  {geocodeSuggestions.map((res, idx) => (
                    <li key={`${res.latitude}-${res.longitude}-${idx}`}>
                      <button
                        type="button"
                        onClick={() => {
                          setDropdownDismissed(true);
                          onSelectGeocode(res);
                        }}
                        className="flex min-h-11 w-full items-start gap-2.5 rounded-xl px-2.5 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                      >
                        <Search
                          aria-hidden="true"
                          className="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"
                        />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-xs font-semibold text-[var(--text-primary)]">
                            {res.label}
                          </p>
                          {res.address && (
                            <p className="truncate text-[11px] text-[var(--text-muted)]">
                              {res.address}
                            </p>
                          )}
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </div>

      <nav aria-label="Account" className="order-2 flex items-center gap-2 md:order-3">
        {canUseGoogleMaps && (
          <button
            type="button"
            onClick={onToggleRenderer}
            className="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-black/10 px-3 text-xs font-semibold hover:border-[var(--accent-brand)] dark:border-white/15"
          >
            {renderer === "google" ? "Google Maps" : "MapLibre"}
          </button>
        )}

        {user ? (
          <>
            <span className="hidden text-xs font-medium text-[var(--text-muted)] lg:inline">
              {user.displayName}
            </span>
            {isAdmin && (
              <Link
                href="/invites"
                aria-label="Invites"
                className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/10"
              >
                <Users aria-hidden="true" className="h-4 w-4" />
                <span className="hidden sm:inline">Invites</span>
              </Link>
            )}
            <button
              type="button"
              aria-label="Sign out"
              onClick={onSignOut}
              className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/10"
            >
              <LogOut aria-hidden="true" className="h-4 w-4" />
              <span className="hidden sm:inline">Sign out</span>
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={onOpenSignIn}
            className="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-[var(--accent-brand)] px-3.5 text-xs font-semibold text-white hover:opacity-90"
          >
            <LogIn aria-hidden="true" className="h-4 w-4" />
            Sign in
          </button>
        )}
      </nav>
    </header>
  );
}
