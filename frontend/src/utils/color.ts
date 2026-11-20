const HEX_COLOR_RE = /^#[0-9a-fA-F]{3,8}$/;
export const DEFAULT_BRAND_COLOR = "#E11D48";

/**
 * Validates that a user-supplied list color is a safe CSS hex literal.
 */
export function sanitizeHexColor(color?: string | null): string {
  if (!color) {
    return DEFAULT_BRAND_COLOR;
  }
  const trimmed = color.trim();
  return HEX_COLOR_RE.test(trimmed) ? trimmed : DEFAULT_BRAND_COLOR;
}
