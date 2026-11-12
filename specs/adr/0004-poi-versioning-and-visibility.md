# ADR-0004: Versioned POI Edits & Granular Visibility Invariant

**Status:** Accepted  
**Date:** 2026-11-12

---

## Context

Friends collaborating on a shared food map need to fix typos, update addresses, or refine descriptions on existing POIs without silently destroying each other's contributions. At the same time, users need fine-grained control over what is private to the friend group vs. visible to unauthenticated visitors on the public homepage.

## Decisions

### 1. Wiki-Style Versioned POI Edits
- Any authenticated member can edit an existing POI's core attributes (`name`, `description`, `address`, `latitude`, `longitude`, `lists`).
- The `pois` table carries a `version` integer column for **optimistic locking** (`409 Conflict` if a client submits a stale `version`).
- Every successful edit appends an immutable row to `poi_revisions` containing `revision_number`, `editor_id`, `changed_fields` (JSON diff + snapshot), and `created_at`.
- POI creators and Admins can view revision history (`GET /api/pois/{id}/revisions`) and revert to any prior revision (`POST /api/pois/{id}/revisions/{rev}/revert`), which itself creates a new forward revision.

### 2. Granular Visibility & Parent-Child Invariant
- All newly created entities (`POI`, `Review`, `Comment`, `Reaction`, `Photo`) default to `is_public = false`.
- Users can toggle `is_public` on POIs they created and individually on each review, comment, reaction, or photo they own.
- **Strict Parent-Child Invariant:** Unauthenticated Guests on the homepage can *only* view child items (`Review`, `Comment`, `Photo`, `Reaction`, `ActivityEvent`) when **both** `item.is_public = true` **and** `parent_poi.is_public = true`.
- Photo bytes are served through an authenticated/authorized Symfony endpoint (`GET /api/photos/{id}/file`) rather than static public file paths, guaranteeing private photos are never reachable by guessing URLs.

## Consequences

- **Positive:** Safe collaborative editing with full auditability; zero accidental data leaks of private photos or private POIs.
- **Negative:** Slightly higher query complexity (joining `pois.is_public` for guest queries) and photo serving through PHP/X-Sendfile rather than raw static paths.
