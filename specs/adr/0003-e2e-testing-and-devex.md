# ADR-0003: E2E Testing Framework & DevContainer Mail Sidecar

**Status:** Accepted  
**Date:** 2026-11-12

---

## Context

`yummy` relies on email Magic Links, one-time invite flows, an installable mobile-first PWA, and interactive WebGL maps. We need an automated E2E testing framework and a local DevContainer stack (with MariaDB and an SMTP test server) that can verify real email flows and mobile browser behavior.

## Decisions

### 1. E2E Testing: Playwright (over Cypress)
- **Playwright** is selected for E2E testing.
- **Why:**
  - Native **WebKit** engine support alongside Chromium/Firefox, enabling realistic testing of iOS Safari / mobile PWA viewport and cookie behavior.
  - Fast parallel execution in CI and DevContainers with zero commercial licensing gates.
  - Built-in trace viewer, network interception, and clean async API for calling Mailpit's REST API during auth tests.

### 2. Mail Sidecar: Mailpit (over Mailcatcher)
- **Mailpit** (`axllent/mailpit`) is selected as the DevContainer SMTP sidecar.
- **Why:**
  - Actively maintained single Go binary with low memory footprint.
  - Exposes a clean **REST API** (`/api/v1/messages`) on port `8025` alongside SMTP on `1025`, allowing Playwright tests to programmatically fetch magic-link and invite emails and click the real verification URLs.

## Consequences

- **Positive:** Full end-to-end verification of magic-link authentication, mobile PWA layouts, and WebGL map interactions in CI without external email providers.
- **Negative:** DevContainer image needs Playwright browser dependencies installed (handled via standard Playwright Docker/devcontainer setup).
