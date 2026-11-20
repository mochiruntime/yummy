import type { ApiErrorPayload, ApiViolation } from "./types";

const STATE_CHANGING_METHODS = new Set(["POST", "PATCH", "PUT", "DELETE"]);

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly violations: ApiViolation[] = [],
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Returns the first validation message matching a given field path, if any. */
  getFieldError(field: string): string | undefined {
    return this.violations.find((v) => v.propertyPath === field)?.message;
  }
}

/**
 * Reads a cookie value by exact name from `document.cookie`.
 */
export function getCookie(name: string): string | null {
  const prefix = `${name}=`;
  for (const part of document.cookie.split(";")) {
    const trimmed = part.trim();
    if (trimmed.startsWith(prefix)) {
      const raw = trimmed.slice(prefix.length);
      try {
        return decodeURIComponent(raw);
      } catch {
        return raw;
      }
    }
  }
  return null;
}

let pendingCsrfRequest: Promise<string> | null = null;

/**
 * Ensures an XSRF-TOKEN cookie is present, calling `GET /api/auth/csrf` if needed.
 * Deduplicates concurrent CSRF bootstrap calls.
 */
async function ensureCsrfToken(forceRefresh = false): Promise<string> {
  if (!forceRefresh) {
    const existing = getCookie("XSRF-TOKEN");
    if (existing) {
      return existing;
    }
  }

  if (!pendingCsrfRequest) {
    pendingCsrfRequest = fetch("/api/auth/csrf", {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((res) => {
        if (!res.ok) {
          throw new ApiError(res.status, "Failed to initialize CSRF token.");
        }
        const token = getCookie("XSRF-TOKEN");
        if (!token) {
          throw new ApiError(500, "CSRF cookie was not set by the server.");
        }
        return token;
      })
      .finally(() => {
        pendingCsrfRequest = null;
      });
  }

  return pendingCsrfRequest;
}

export interface ApiFetchOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
}

async function parseErrorResponse(res: Response): Promise<ApiError> {
  const fallback = res.statusText || `Request failed with status ${res.status}`;
  try {
    const data = (await res.json()) as Partial<ApiErrorPayload>;
    const message = typeof data.error === "string" && data.error.length > 0 ? data.error : fallback;
    const violations = Array.isArray(data.violations) ? data.violations : [];
    return new ApiError(res.status, message, violations);
  } catch {
    return new ApiError(res.status, fallback);
  }
}

/**
 * Typed fetch wrapper for `/api/*` endpoints.
 *
 * - Always sends `credentials: "include"` for session cookies.
 * - Automatically attaches `X-XSRF-TOKEN` on state-changing requests (`POST`, `PATCH`, `PUT`, `DELETE`),
 *   bootstrapping `/api/auth/csrf` if needed and retrying once on CSRF 403.
 * - Normalizes `{"error", "violations"}` responses into `ApiError`.
 */
export async function apiFetch<T>(
  path: string,
  options: ApiFetchOptions = {},
  _retriedCsrf = false,
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const isMutation = STATE_CHANGING_METHODS.has(method);
  const headers = new Headers(options.headers);

  headers.set("Accept", "application/json");

  let serializedBody: BodyInit | undefined;
  if (options.body !== undefined) {
    if (options.body instanceof FormData) {
      serializedBody = options.body;
    } else {
      headers.set("Content-Type", "application/json");
      serializedBody = JSON.stringify(options.body);
    }
  }

  if (isMutation) {
    const csrfToken = await ensureCsrfToken(_retriedCsrf);
    headers.set("X-XSRF-TOKEN", csrfToken);
  }

  const res = await fetch(path, {
    ...options,
    method,
    headers,
    credentials: "include",
    body: serializedBody,
  });

  if (!res.ok) {
    const apiError = await parseErrorResponse(res);
    if (
      isMutation &&
      !_retriedCsrf &&
      res.status === 403 &&
      apiError.message.toLowerCase().includes("csrf")
    ) {
      return apiFetch<T>(path, options, true);
    }
    throw apiError;
  }

  if (res.status === 204) {
    return undefined as T;
  }

  return (await res.json()) as T;
}
