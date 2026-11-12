# ADR-0002: Authentication & Friend Invite Flows

**Status:** Accepted  
**Date:** 2026-11-12

---

## Context

`yummy` is a private-first collaborative map for friends. We want to avoid heavy username/password registration flows while remaining secure, multi-device friendly, and free of proprietary ecosystem lock-in.

We evaluated two primary models:
- **Option A:** A reusable "secret" group-chat link where friends register by picking a display name on first click, and log in again on subsequent clicks (potentially paired with WebAuthn Passkeys or personal transfer tokens).
- **Option B:** One-time invite links per friend for registration, paired with email Magic Links for all subsequent logins.

## Analysis of Option A (Rejected)

1. **Multi-Device / Cleared-Cookie Impersonation Risk:** If Alice registers on her phone via a reusable group link and later clicks the same link on her laptop, the server has no session cookie from her phone. It cannot distinguish Alice on a new device from a new friend Bob. Allowing her to pick "Alice" from a list would let anyone with the group link impersonate any existing user.
2. **iOS PWA Storage Isolation:** On iOS, clicking a link inside WhatsApp/Signal opens an in-app browser whose cookies are isolated from Safari and from an installed Home Screen PWA—meaning users would immediately lose their session upon installing the PWA.
3. **Passkey Sync Lock-In:** Relying on WebAuthn Passkeys to solve multi-device login depends on proprietary OS sync (iCloud Keychain, Google Password Manager, Microsoft Account). Users on Linux, Firefox, de-Googled Android, or mixed ecosystems without a third-party password manager cannot sync passkeys across devices.

## Decision: Option B — One-Time Invite Links + Email Magic Links Only

1. **Admin Login:** Admins request a short-lived (15-minute), single-use **Email Magic Link** (`POST /api/auth/magic-link`).
2. **Friend Onboarding:** Admins generate **one-time invite links** (`POST /api/auth/invites`) to paste into IM apps.
3. **Invite Claim:** A friend opens the link, enters their **Display Name** and **Email**, and receives an authenticated session (`HttpOnly; Secure; SameSite=Lax` cookie). `GET` requests to invite or magic-link URLs (such as IM link-preview auto-crawlers) never consume the token; consumption occurs strictly on explicit `POST` form submission.
4. **Subsequent Logins (Any Device / PWA):** Friends log in on any device, browser, or installed PWA by requesting an **Email Magic Link**.
5. **No Passkeys / No Passwords:** Passkeys and personal transfer tokens are intentionally omitted to keep the auth surface simple, auditable, and 100% open-standard.

## Consequences

- **Positive:** Zero passwords to manage; works identically across iOS PWA, Android, Linux, and desktop browsers; no proprietary cloud sync dependency; eliminates group-link impersonation.
- **Negative:** Requires each friend to have an email address and the Admin to generate one invite link per friend.
