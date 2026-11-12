# Testing & DevEx Specification: `yummy`

**Related ADR:** [ADR-0003](./adr/0003-e2e-testing-and-devex.md)

---

## 1. Local Development: DevContainer Topology

Local development runs inside a Docker Compose-backed `.devcontainer/` so every contributor has identical PHP, Node, MariaDB, and SMTP tooling:

| Service | Image / Runtime | Purpose | Ports |
| :--- | :--- | :--- | :--- |
| `app` | PHP 8.3/8.4 + Composer + Node 22 + Playwright deps | Runs Symfony API, Vite dev server, PHPUnit, Vitest, Playwright | `8000` (API), `5173` (Vite) |
| `mariadb` | `mariadb:11` | Primary + test database with native spatial `POINT` support | `3306` |
| `mailpit` | `axllent/mailpit` | Local SMTP trap + Web UI + REST API for magic-link & invite testing | `1025` (SMTP), `8025` (UI/API) |

---

## 2. Test Pyramid & Commands

### 2.1 Backend (Symfony / PHP)
- **Static Analysis:** `vendor/bin/phpstan analyse` (level 8 target).
- **Unit & Integration Tests:** `php bin/phpunit`
  - Uses a dedicated test database (`yummy_test`) in MariaDB.
  - Covers Doctrine spatial queries, optimistic locking (`409 Conflict`), revision diffs/reverts, EXIF stripping, and strict `Guest` vs `Member` visibility invariants.

### 2.2 Frontend (React / TypeScript)
- **Typecheck & Lint:** `npm run typecheck && npm run lint`
- **Unit & Component Tests:** `npx vitest run` (React Testing Library)
  - Covers multi-list filter state, bottom-sheet interactions, visibility badge/warning logic, and zero-preload guarantee for Google Maps SDK.

### 2.3 End-to-End (Playwright)
- **Command:** `npx playwright test`
- **Browsers:** Desktop Chromium + Mobile WebKit (iOS PWA viewport emulation).
- **Critical E2E Journeys:**
  1. **Admin Magic Link & Invite Flow:** Admin requests magic link -> Playwright fetches link from Mailpit REST API (`GET http://mailpit:8025/api/v1/messages`) -> logs in -> generates one-time invite -> friend claims invite -> logs out -> logs back in via magic link.
  2. **POI Lifecycle & Versioning:** Member drops pin / searches geocode -> assigns 2 lists -> second member edits description -> creator views revision history & reverts.
  3. **Granular Privacy & Public Homepage:** Member creates private POI with 2 photos (1 public, 1 private) -> Guest homepage shows 0 items -> Member toggles POI public -> Guest homepage shows POI + 1 public photo only (private photo returns `404` for Guest).
  4. **Zero-Leakage Map Renderer:** Assert zero network requests to `googleapis.com` on initial load with MapLibre default.
