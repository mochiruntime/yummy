import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { Router } from "wouter";
import { memoryLocation } from "wouter/memory-location";
import { AppRoutes } from "../../../App";
import { AuthProvider } from "../../../context/AuthContext";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

const DEFAULT_CONFIG = {
  googleMapsEnabled: false,
  googleMapsApiKey: null,
  defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
  pmtilesUrl: null,
  geocoderProvider: "photon" as const,
  publicBrowsingEnabled: true,
};

const ADMIN_USER = {
  id: "01950000-0000-7000-8000-000000000001",
  displayName: "Admin",
  email: "admin@example.com",
  roles: ["ROLE_ADMIN", "ROLE_USER"],
};

function renderAt(
  path: string,
  fetchImpl: (url: string, init?: RequestInit) => Promise<Response>,
) {
  document.cookie = "XSRF-TOKEN=test-xsrf; path=/";
  vi.stubGlobal("fetch", vi.fn((input: RequestInfo | URL, init?: RequestInit) => fetchImpl(String(input), init)));

  const { hook } = memoryLocation({ path, record: true });
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  });

  const view = render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Router hook={hook}>
          <AppRoutes />
        </Router>
      </AuthProvider>
    </QueryClientProvider>,
  );

  return { ...view, queryClient };
}

describe("Auth & Invite Flows", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("does not consume magic-link token on GET/mount, only on explicit button click", async () => {
    const postCalls: string[] = [];

    renderAt("/magic-link/raw-magic-token-12345678901234567890", async (url, init) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
      if (url === "/api/auth/magic-link/verify" && init?.method === "POST") {
        postCalls.push(url);
        return jsonResponse(ADMIN_USER);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    const signInButton = await screen.findByRole("button", { name: /sign in to yummy/i });
    // Crucial ADR-0002 check: zero POST calls on initial render
    expect(postCalls).toEqual([]);

    fireEvent.click(signInButton);

    await waitFor(() => {
      expect(postCalls).toEqual(["/api/auth/magic-link/verify"]);
    });
    expect(await screen.findByText("Admin")).toBeInTheDocument();
  });

  it("does not consume invite on GET/mount and surfaces 422 field violations", async () => {
    const postCalls: string[] = [];
    let attempt = 0;

    renderAt("/join/invite-token-xyz", async (url, init) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
      if (url === "/api/auth/invites/invite-token-xyz/claim" && init?.method === "POST") {
        postCalls.push(url);
        attempt += 1;
        if (attempt === 1) {
          return jsonResponse(
            {
              error: "Display name must be at least 2 characters.",
              violations: [{ propertyPath: "displayName", message: "Display name must be at least 2 characters." }],
            },
            422,
          );
        }
        return jsonResponse(
          {
            id: "01950000-0000-7000-8000-000000000002",
            displayName: "Alex",
            email: "alex@example.com",
            roles: ["ROLE_USER"],
          },
          201,
        );
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    const nameInput = await screen.findByLabelText(/display name/i);
    const emailInput = screen.getByLabelText(/email address/i);
    const submitBtn = screen.getByRole("button", { name: /join yummy/i });

    expect(postCalls).toEqual([]);

    fireEvent.change(nameInput, { target: { value: "A" } });
    fireEvent.change(emailInput, { target: { value: "alex@example.com" } });
    fireEvent.click(submitBtn);

    expect(await screen.findByText(/at least 2 characters/i)).toBeInTheDocument();

    fireEvent.change(nameInput, { target: { value: "Alex" } });
    fireEvent.click(submitBtn);

    expect(await screen.findByText("Alex")).toBeInTheDocument();
  });

  it("enforces publicBrowsingEnabled=false sign-in gate and clears cache on logout", async () => {
    let signedIn = false;

    const { queryClient } = renderAt("/", async (url, init) => {
      if (url === "/api/config") {
        return jsonResponse({ ...DEFAULT_CONFIG, publicBrowsingEnabled: false });
      }
      if (url === "/api/auth/me") {
        return signedIn ? jsonResponse(ADMIN_USER) : jsonResponse({ error: "Unauthorized" }, 401);
      }
      if (url === "/api/auth/logout" && init?.method === "POST") {
        signedIn = false;
        return new Response(null, { status: 204 });
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    expect(await screen.findByText(/private instance — sign in required/i)).toBeInTheDocument();

    // Simulate authenticated session and logout clearing cached queries
    signedIn = true;
    queryClient.setQueryData(["auth", "me"], ADMIN_USER);
    queryClient.setQueryData(["private-pois"], [{ id: "poi-1" }]);

    const signOutBtn = await screen.findByRole("button", { name: /sign out/i });
    fireEvent.click(signOutBtn);

    await waitFor(() => {
      expect(queryClient.getQueryData(["private-pois"])).toBeUndefined();
      expect(screen.getByText(/private instance — sign in required/i)).toBeInTheDocument();
    });
  });

  it("allows admins to list, create labeled invites, see who claimed them, and revoke", async () => {
    let invites: Array<{
      id: string;
      label: string | null;
      createdAt: string;
      expiresAt: string;
      claimedAt: string | null;
      claimedBy: { id: string; displayName: string; email: string } | null;
      status: string;
    }> = [
      {
        id: "11111111-1111-7111-8111-111111111111",
        label: null,
        createdAt: "2026-11-20T10:00:00+00:00",
        expiresAt: "2026-11-27T10:00:00+00:00",
        claimedAt: null,
        claimedBy: null,
        status: "pending",
      },
      {
        id: "33333333-3333-7333-8333-333333333333",
        label: "For Alex",
        createdAt: "2026-11-19T10:00:00+00:00",
        expiresAt: "2026-11-26T10:00:00+00:00",
        claimedAt: "2026-11-20T09:00:00+00:00",
        claimedBy: {
          id: "01950000-0000-7000-8000-000000000002",
          displayName: "Alex",
          email: "alex@example.com",
        },
        status: "claimed",
      },
    ];

    renderAt("/invites", async (url, init) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") return jsonResponse(ADMIN_USER);
      if (url === "/api/auth/invites" && (!init?.method || init.method === "GET")) {
        return jsonResponse(invites);
      }
      if (url === "/api/auth/invites" && init?.method === "POST") {
        const parsed = JSON.parse(String(init.body)) as { label?: string };
        const newInvite = {
          id: "22222222-2222-7222-8222-222222222222",
          label: parsed.label ?? null,
          createdAt: "2026-11-20T12:00:00+00:00",
          expiresAt: "2026-11-27T12:00:00+00:00",
          claimedAt: null,
          claimedBy: null,
          status: "pending",
        };
        invites = [newInvite, ...invites];
        return jsonResponse(
          {
            id: newInvite.id,
            label: newInvite.label,
            inviteUrl: "http://localhost:5173/join/secret-token-abc",
            expiresAt: newInvite.expiresAt,
          },
          201,
        );
      }
      if (url === "/api/auth/invites/11111111-1111-7111-8111-111111111111" && init?.method === "DELETE") {
        invites = invites.map((inv) =>
          inv.id === "11111111-1111-7111-8111-111111111111" ? { ...inv, status: "revoked" } : inv,
        );
        return new Response(null, { status: 204 });
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    expect(await screen.findByRole("heading", { name: /friend invites/i })).toBeInTheDocument();
    expect(await screen.findByText("11111111")).toBeInTheDocument();
    expect(screen.getByText("For Alex")).toBeInTheDocument();
    expect(screen.getByText(/alex@example\.com/i)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/label \(optional\)/i), {
      target: { value: "For Maria (Signal)" },
    });
    fireEvent.click(screen.getByRole("button", { name: /generate invite link/i }));

    const urlInput = await screen.findByLabelText(/generated invite url/i);
    expect(urlInput).toHaveValue("http://localhost:5173/join/secret-token-abc");
    expect(await screen.findByText("For Maria (Signal)")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /revoke invite 11111111/i }));
    expect(await screen.findByText("revoked")).toBeInTheDocument();
  });

  it("blocks non-admin users from /invites without fetching invite list", async () => {
    let fetchedInvites = false;

    renderAt("/invites", async (url) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") {
        return jsonResponse({
          id: "01950000-0000-7000-8000-000000000003",
          displayName: "Friend",
          email: "friend@example.com",
          roles: ["ROLE_USER"],
        });
      }
      if (url === "/api/auth/invites") {
        fetchedInvites = true;
        return jsonResponse([]);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    expect(await screen.findByText(/admin access is required/i)).toBeInTheDocument();
    expect(fetchedInvites).toBe(false);
  });

  it("opens Sign In modal for guests, submits magic-link request, and displays 429 errors", async () => {
    let calls = 0;

    renderAt("/", async (url, init) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
      if (url === "/api/auth/magic-link" && init?.method === "POST") {
        calls += 1;
        if (calls === 1) {
          return jsonResponse({ message: "If an account exists, a sign-in link has been sent." }, 202);
        }
        return jsonResponse({ error: "Too many requests. Try again later." }, 429);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    const signInBtn = await screen.findByRole("button", { name: /^sign in$/i });
    signInBtn.focus();
    fireEvent.click(signInBtn);

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toBeInTheDocument();

    const emailInput = screen.getByLabelText(/email address/i);
    expect(emailInput).toHaveFocus();

    const submitBtn = screen.getByRole("button", { name: /email me a sign-in link/i });

    fireEvent.change(emailInput, { target: { value: "friend@example.com" } });
    fireEvent.click(submitBtn);

    expect(await screen.findByText(/if an account exists, a sign-in link has been sent/i)).toBeInTheDocument();

    fireEvent.click(submitBtn);
    expect(await screen.findByText(/too many requests/i)).toBeInTheDocument();

    // Escape closes dialog and restores focus to trigger button
    fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(signInBtn).toHaveFocus();
  });

  it("shows error message on MagicLinkVerifyPage when token is expired or invalid", async () => {
    renderAt("/magic-link/expired-token-12345678901234567890", async (url, init) => {
      if (url === "/api/config") return jsonResponse(DEFAULT_CONFIG);
      if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
      if (url === "/api/auth/magic-link/verify" && init?.method === "POST") {
        return jsonResponse({ error: "Invalid or expired magic link." }, 400);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    const btn = await screen.findByRole("button", { name: /sign in to yummy/i });
    fireEvent.click(btn);

    expect(await screen.findByText(/invalid or expired magic link/i)).toBeInTheDocument();
  });
});
