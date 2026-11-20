import { LogIn, LogOut, MapPin, Search, Users, X } from "lucide-react";
import { Link } from "wouter";
import type { GeocodeResult, User } from "../../api/types";

export interface TopNavBarProps {
  user: User | null;
  isAdmin: boolean;
  searchText: string;
  onSearchTextChange: (value: string) => void;
  onClearSearch: () => void;
  geocodeSuggestions: GeocodeResult[];
  showGeocodeDropdown: boolean;
  geocodeRateLimited?: boolean;
  onSelectGeocode: (result: GeocodeResult) => void;
  canUseGoogleMaps: boolean;
  renderer: "maplibre" | "google";
  onToggleRenderer: () => void;
  onOpenSignIn: () => void;
  onSignOut: () => void;
}

/**
 * Unified top navigation bar with brand link, centered search + geocode autocomplete,
 * conditional map renderer toggle, and account controls.
 */
export function TopNavBar({
  user,
  isAdmin,
  searchText,
  onSearchTextChange,
  onClearSearch,
  geocodeSuggestions,
  showGeocodeDropdown,
  geocodeRateLimited = false,
  onSelectGeocode,
  canUseGoogleMaps,
  renderer,
  onToggleRenderer,
  onOpenSignIn,
  onSignOut,
}: TopNavBarProps) {
  return (
    <header className="relative z-30 flex flex-wrap items-center justify-between gap-2 border-b border-black/10 bg-[var(--surface-overlay)] px-4 py-2 backdrop-blur-md dark:border-white/10">
      <Link href="/" className="inline-flex min-h-11 items-center gap-2 font-bold tracking-tight">
        <MapPin aria-hidden="true" className="h-5 w-5 text-[var(--accent-brand)]" />
        <span>yummy</span>
      </Link>

      {/* Centered Search Bar (wraps onto its own row on mobile, centered on md+) */}
      <div className="relative order-3 w-full md:order-2 md:mx-4 md:max-w-md md:flex-1">
        <Search
          aria-hidden="true"
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
        />
        <input
          type="search"
          aria-label="Search places"
          value={searchText}
          onChange={(e) => onSearchTextChange(e.target.value)}
          placeholder="Search spots or jump to an address…"
          className="min-h-11 w-full rounded-xl border border-black/15 bg-[var(--surface-primary)] py-2 pl-9 pr-9 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
        />
        {searchText && (
          <button
            type="button"
            onClick={onClearSearch}
            aria-label="Clear search"
            className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)]"
          >
            <X aria-hidden="true" className="h-4 w-4" />
          </button>
        )}

        {showGeocodeDropdown && geocodeRateLimited && (
          <div
            role="status"
            className="absolute left-0 right-0 top-12 z-40 rounded-2xl border border-amber-500/30 bg-[var(--surface-primary)] px-3.5 py-2.5 text-xs text-amber-800 shadow-xl dark:text-amber-200"
          >
            Too many searches — please wait a moment.
          </div>
        )}

        {showGeocodeDropdown && !geocodeRateLimited && geocodeSuggestions.length > 0 && (
          <ul
            aria-label="Address suggestions"
            className="absolute left-0 right-0 top-12 z-40 max-h-72 overflow-y-auto rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-1 shadow-xl dark:border-white/15"
          >
            {geocodeSuggestions.map((res, idx) => (
              <li key={`${res.latitude}-${res.longitude}-${idx}`}>
                <button
                  type="button"
                  onClick={() => onSelectGeocode(res)}
                  className="flex min-h-11 w-full items-start gap-2.5 rounded-xl px-3 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                >
                  <MapPin
                    aria-hidden="true"
                    className="mt-0.5 h-4 w-4 shrink-0 text-[var(--accent-brand)]"
                  />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-semibold text-[var(--text-primary)]">
                      {res.label}
                    </p>
                    {res.address && (
                      <p className="truncate text-[11px] text-[var(--text-muted)]">{res.address}</p>
                    )}
                  </div>
                </button>
              </li>
            ))}
          </ul>
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
