# Product Requirements Document (PRD): `yummy`

**Status:** Approved  
**Target Stack:** React SPA PWA (TypeScript + Tailwind CSS) + Symfony API (PHP 8.3+) + MariaDB 11 (LAMP-compatible production)

---

## 1. Vision & Goals

`yummy` is an open-source, privacy-first, collaborative mapping Progressive Web App (PWA) designed for small groups of friends to drop, share, review, and organize custom Points of Interest (POIs)—primarily food spots such as restaurants, cafés, bakeries, and bars.

### Core Principles
1. **Privacy by Default:** All user-created data is private to authenticated members unless explicitly marked public. Zero third-party trackers or map SDKs load without explicit user consent.
2. **Low-Friction Friend Onboarding:** Friends join via one-time invite links and authenticate with passwordless email magic links—no passwords to remember, no proprietary passkey-sync lock-in.
3. **Mobile-First Installable PWA:** Fast, thumb-friendly map and bottom-sheet UX installable on iOS and Android home screens.
4. **LAMP-Compatible Self-Hosting:** Production deployment requires only a standard LAMP-style host (static frontend assets + PHP 8.3+ + MariaDB 11), with DevContainers for local development.

---

## 2. Personas & Roles

| Role | Description | Capabilities |
| :--- | :--- | :--- |
| **Admin** | Instance owner / organizer | Logs in via email magic link; generates and revokes one-time friend invite links; moderates all POIs, revisions, reviews, comments, photos, and users; configures instance settings. |
| **Member** | Invited friend | Claims a one-time invite link (display name + email); logs in via email magic link; creates/edits POIs (versioned); assigns POIs to lists; writes reviews, comments, reactions; uploads photos; toggles `is_public` on own POIs and own individual items. |
| **Guest** | Unauthenticated visitor | Views `is_public = true` POIs, their `is_public = true` reviews/comments/photos, and the public activity feed on the instance homepage. Cannot create, edit, or view private data. |

---

## 3. Functional Requirements (FR)

### 3.1 Authentication & Invites
- **FR-AUTH-01:** Admins authenticate by requesting an email **Magic Link** (short-lived, single-use token).
- **FR-AUTH-02:** Admins generate **one-time invite links** (copyable for pasting into IM apps like Signal, WhatsApp, or Telegram). `GET` requests to an invite or magic-link URL (e.g., from IM link-preview crawlers) must **never** consume or invalidate the token; tokens are only consumed on explicit `POST` submission.
- **FR-AUTH-03:** A friend clicking a valid one-time invite link registers by providing a **Display Name** and **Email**; the invite token is immediately invalidated upon claim, and an authenticated session is established.
- **FR-AUTH-04:** Subsequent logins on any device or browser use **Email Magic Links** only (no passwords, no passkeys).
- **FR-AUTH-05:** Admins can list pending/claimed invites and revoke unused invite links.

### 3.2 Map Rendering & Geocoding
- **FR-MAP-01:** Default map renderer is **MapLibre GL JS** using privacy-friendly vector tiles (**OpenFreeMap** by default, or self-hosted **Protomaps `.pmtiles`** via configuration).
- **FR-MAP-02:** Users can switch their render target to **Google Maps** *only if* enabled by the instance owner (`GOOGLE_MAPS_ENABLED=true`).
- **FR-MAP-03:** Switching to Google Maps displays an explicit privacy warning modal explaining that Google scripts will be loaded and IP/viewport data shared. **Zero** Google requests occur before explicit consent.
- **FR-MAP-04:** Place autocomplete uses a server-proxied, MariaDB-cached **Photon / Nominatim** endpoint (`GET /api/geocode`) by default, with manual map pin-drop always available as a zero-dependency fallback, and Google Places Autocomplete available only after Google Maps opt-in.

### 3.3 POIs, Multi-List Categorization & Versioning
- **FR-POI-01:** Authenticated members can create POIs via search autocomplete or manual map pin-drop, providing `name`, `latitude`, `longitude`, optional `address`, `description`, and initial `lists`.
- **FR-POI-02:** A POI can belong to **multiple lists** simultaneously (e.g., `"Restaurant"` AND `"Trip to Portugal 2026"`). Lists have owners; only a list's owner (or an Admin) can add/remove POIs from that list.
- **FR-POI-03:** Members can create, rename, color-code, and filter the map by lists.
- **FR-POI-04:** Any authenticated member can edit an existing POI's core fields (`name`, `description`, `address`, `coordinates`). Editing a POI cannot remove it from another user's list.
- **FR-POI-05:** Every POI edit creates an immutable **POI Revision** (`version`, `editor`, `changed_fields`, `created_at`) using optimistic locking to prevent silent overwrites. Creators and Admins can view revision history and revert to a prior revision.

### 3.4 Reviews, Comments, Reactions & Photos
- **FR-SOC-01:** Members (including the POI creator) can add a personal **Review** (numeric/star rating + text + optional attached photos) to a POI. Photos attached to a review are also included in the POI's unified gallery (ordered by direct POI photos first, then review photos), with optional origin labels and strict respect for visibility settings.
- **FR-SOC-02:** Members can add **Comments** and **Emoji Reactions** on POIs or reviews (per-photo comments/reactions are omitted in v1 to avoid fragmented threads).
- **FR-SOC-03:** Members can upload multiple **Photos** to a POI. All uploaded photos have EXIF/GPS metadata stripped server-side before storage.

### 3.5 Granular Public/Private Visibility
- **FR-VIS-01:** Every newly created POI, review, comment, reaction, and photo defaults to `is_public = false` (visible only to logged-in members).
- **FR-VIS-02:** POI creators (and Admins) can toggle `is_public` on their POI.
- **FR-VIS-03:** Users can toggle `is_public` individually per piece of data they own (e.g., uploading 5 photos for a restaurant and marking only 2 as public).
- **FR-VIS-04 (Visibility Invariant):** If a parent POI has `is_public = false`, **none** of its child reviews, comments, reactions, or photos are ever exposed to Guests, regardless of their individual `is_public` flags.
- **FR-VIS-05:** Guests visiting the instance homepage see all `is_public = true` POIs, public child items, and public activity feed events.

### 3.6 Activity Feed
- **FR-FEED-01:** A chronological Activity Feed displays recent actions: `poi.created`, `poi.edited`, `review.added`, `comment.added`, `photo.added`, and `reaction.added`.
- **FR-FEED-02:** Feed entries strictly respect viewer permissions: Guests only see events where both the event target and its parent POI are `is_public = true`.

---

## 4. Non-Functional Requirements (NFR)

- **NFR-PWA-01:** Installable PWA (`manifest.webmanifest`, `display: "standalone"`, iOS/Android icons, Workbox service worker for app-shell caching).
- **NFR-PWA-02:** Service worker must **never** cache authenticated private API responses in shared/public caches; logout must clear user-scoped client state.
- **NFR-PRIV-01:** No Google fonts, analytics, or third-party map tiles/scripts loaded without explicit opt-in or privacy-safe defaults.
- **NFR-SEC-01:** All invite and magic-link tokens stored as SHA-256 hashes; `HttpOnly; Secure; SameSite=Lax` session cookies; CSRF protection on state-changing routes.
- **NFR-HOST-01:** Production runtime requires only static file serving + PHP 8.3+ + MariaDB 11 (no mandatory Docker/Java/Node background daemons).
