# ADR-0001: Map Renderer, Geocoding & Privacy Boundaries

**Status:** Accepted  
**Date:** 2026-11-12

---

## Context

`yummy` needs an interactive, mobile-friendly map and place search experience without leaking user IP addresses, browsing habits, or location data to Google by default. Additionally, the production target is a standard **LAMP** host (PHP + MariaDB + static assets), meaning heavy non-LAMP search daemons (e.g., Java + Elasticsearch for self-hosted Photon) cannot be assumed in production.

## Decision

1. **Default Map Renderer — MapLibre GL JS:**
   - Use **MapLibre GL JS** (open-source WebGL vector renderer) for smooth 60fps mobile pan/zoom.
   - Default tile source: **OpenFreeMap** (free, no API key, no tracking).
   - Optional self-hosted override: **Protomaps (`.pmtiles`)**, which is a single static file served via standard HTTP Range requests directly from Apache/Nginx on a LAMP host.

2. **Opt-In Google Maps & Server Kill Switch:**
   - Instance owners control `GOOGLE_MAPS_ENABLED` (`false` by default) and `GOOGLE_MAPS_API_KEY` via server environment variables, exposed through `GET /api/config`.
   - When enabled on the server, the client still defaults to MapLibre and makes **zero** requests to Google domains (`maps.googleapis.com`, etc.).
   - Switching to Google Maps in user preferences opens an explicit privacy warning modal; only after confirmation does the SPA dynamically load `@googlemaps/js-api-loader`.

3. **Geocoding & Place Autocomplete (3-Tier LAMP-Compatible Strategy):**
   - **Tier 1 (Default — Server-Proxied + Cached):** `GET /api/geocode?q=...` proxies requests from Symfony to public **Photon** (`photon.komoot.io`) or **Nominatim** (`nominatim.openstreetmap.org`) and caches responses in MariaDB (`geocode_cache`). This prevents exposing client IP addresses to third-party geocoders and works on LAMP without Java/Elasticsearch. `GEOCODER_PROVIDER` can still point to a self-hosted Photon URL if available.
   - **Tier 2 (Always Available):** Manual pin-drop on the map + manual name/address entry (zero external network calls).
   - **Tier 3 (Opt-In):** Google Places Autocomplete, active only when `GOOGLE_MAPS_ENABLED=true` and the user has consented to Google Maps.

## Consequences

- **Positive:** Zero third-party tracking out of the box; works on any LAMP host; instance owners can completely disable Google Maps; self-hosted `.pmtiles` requires no tile server daemon.
- **Negative:** Public Photon/Nominatim has less rich restaurant metadata (e.g., opening hours, freshly opened venues) than Google Places, mitigated by manual editing and optional Google opt-in.
