import { ApiError, apiFetch } from "./client";
import type { AppConfig, CreatedInvite, InviteSummary, User } from "./types";

export const queryKeys = {
  config: ["config"] as const,
  me: ["auth", "me"] as const,
  invites: ["auth", "invites"] as const,
};

export function fetchConfig(signal?: AbortSignal): Promise<AppConfig> {
  return apiFetch<AppConfig>("/api/config", { signal });
}

export async function fetchMe(signal?: AbortSignal): Promise<User | null> {
  try {
    return await apiFetch<User>("/api/auth/me", { signal });
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      return null;
    }
    throw err;
  }
}

export function requestMagicLink(email: string): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/api/auth/magic-link", {
    method: "POST",
    body: { email },
  });
}

export function verifyMagicLink(token: string): Promise<User> {
  return apiFetch<User>("/api/auth/magic-link/verify", {
    method: "POST",
    body: { token },
  });
}

export function claimInvite(
  token: string,
  payload: { displayName: string; email: string },
): Promise<User> {
  return apiFetch<User>(`/api/auth/invites/${encodeURIComponent(token)}/claim`, {
    method: "POST",
    body: payload,
  });
}

export function logout(): Promise<void> {
  return apiFetch<void>("/api/auth/logout", {
    method: "POST",
  });
}

export function listInvites(signal?: AbortSignal): Promise<InviteSummary[]> {
  return apiFetch<InviteSummary[]>("/api/auth/invites", { signal });
}

export function createInvite(expiresInHours = 168, label?: string): Promise<CreatedInvite> {
  const trimmedLabel = label?.trim();
  return apiFetch<CreatedInvite>("/api/auth/invites", {
    method: "POST",
    body: {
      expiresInHours,
      ...(trimmedLabel ? { label: trimmedLabel } : {}),
    },
  });
}

export function revokeInvite(id: string): Promise<void> {
  return apiFetch<void>(`/api/auth/invites/${encodeURIComponent(id)}`, {
    method: "DELETE",
  });
}
