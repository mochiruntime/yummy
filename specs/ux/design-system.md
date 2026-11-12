# UX & Design System Specification: `yummy`

**Theming Engine:** Tailwind CSS v4  
**Target Form Factors:** Mobile-First Installable PWA (iOS & Android) + Desktop Responsive

---

## 1. Layout & Navigation Architecture

`yummy` uses a **full-bleed map-centric canvas** inspired by modern mapping apps:

- **Mobile (`< 768px`):**
  - Full-viewport interactive map (`100dvh`) with floating top search & multi-list filter pills.
  - **Bottom Sheet** (snap points: collapsed peek, half-height list/feed, full-height POI detail) for browsing the Activity Feed, List filters, or selected POI details.
  - Floating Action Button (`+ Drop Pin`) anchored cleanly above the bottom sheet.
- **Desktop (`>= 768px`):**
  - Full-viewport map with a persistent **left side drawer (`380px–420px`)** for Search, List Filters, Activity Feed, and POI Details.

---

## 2. Tailwind Semantic Design Tokens

All colors use semantic CSS variables mapped in Tailwind so light and dark themes remain legible over busy map tiles:

| Token | Purpose | Light Value | Dark Value |
| :--- | :--- | :--- | :--- |
| `--surface-primary` | Panels, sheets, cards | `#FFFFFF` | `#121417` |
| `--surface-overlay` | Floating map pills (backdrop-blur) | `rgba(255,255,255,0.90)` | `rgba(18,20,23,0.88)` |
| `--text-primary` | Headings, POI names | `#111827` | `#F3F4F6` |
| `--text-muted` | Addresses, timestamps | `#6B7280` | `#9CA3AF` |
| `--accent-brand` | Primary CTAs, active pins | `#E11D48` (warm berry/food) | `#FB7185` |
| `--vis-private` | Friends-only badge/lock | `#4F46E5` (indigo) | `#818CF8` |
| `--vis-public` | Public globe badge | `#059669` (emerald) | `#34D399` |

---

## 3. Visibility Signifiers & Privacy UX

Because `yummy` mixes private friend-group data and public homepage data, users must **never** be confused about who can see an item:
1. **Explicit Badges:** Every POI header, photo thumbnail, and review displays a compact pill:
   - `🔒 Friends Only` (`--vis-private`) by default.
   - `🌐 Public` (`--vis-public`) when explicitly toggled public.
2. **Parent-Child Warning:** If a user toggles a photo or review to `Public` while its parent POI is still `Friends Only`, the UI displays an inline hint: *"Hidden from guests until this POI is also made Public."*
3. **Google Maps Opt-In Modal:** Switching the map renderer to Google Maps (when `googleMapsEnabled: true`) presents a clear modal detailing what data is shared with Google, requiring an explicit **"Enable Google Maps"** confirmation before any Google script loads.

---

## 4. Accessibility & PWA Polish

- Minimum `44×44px` touch targets on all map controls, filter chips, and bottom-sheet handles.
- WCAG AA contrast (`>= 4.5:1`) on all floating map overlays via `backdrop-blur-md` and `--surface-overlay`.
- `manifest.webmanifest` with `display: "standalone"`, `theme_color`, `background_color`, and maskable icons; safe-area insets (`env(safe-area-inset-bottom)`) respected for iOS home indicator.
