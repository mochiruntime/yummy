import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError, apiFetch } from "../client";

describe("apiFetch", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
  });

  it("bootstraps XSRF-TOKEN via GET /api/auth/csrf before a POST when cookie is absent", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url === "/api/auth/csrf") {
        document.cookie = "XSRF-TOKEN=fresh-csrf-token; path=/";
        return new Response(null, { status: 204 });
      }
      expect(init?.credentials).toBe("include");
      expect(new Headers(init?.headers).get("X-XSRF-TOKEN")).toBe("fresh-csrf-token");
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    });

    vi.stubGlobal("fetch", fetchMock);

    const result = await apiFetch<{ ok: boolean }>("/api/test", {
      method: "POST",
      body: { hello: "world" },
    });

    expect(result).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0]?.[0]).toBe("/api/auth/csrf");
    expect(fetchMock.mock.calls[1]?.[0]).toBe("/api/test");
  });

  it("reuses existing XSRF-TOKEN cookie without calling /api/auth/csrf", async () => {
    document.cookie = "XSRF-TOKEN=existing-token; path=/";

    const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      expect(new Headers(init?.headers).get("X-XSRF-TOKEN")).toBe("existing-token");
      return new Response(null, { status: 204 });
    });

    vi.stubGlobal("fetch", fetchMock);

    await apiFetch<void>("/api/auth/logout", { method: "POST" });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("retries once after refreshing CSRF token when server returns 403 CSRF error", async () => {
    document.cookie = "XSRF-TOKEN=stale-token; path=/";
    let postCount = 0;

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url === "/api/auth/csrf") {
        document.cookie = "XSRF-TOKEN=refreshed-token; path=/";
        return new Response(null, { status: 204 });
      }

      postCount += 1;
      const sentToken = new Headers(init?.headers).get("X-XSRF-TOKEN");
      if (postCount === 1) {
        expect(sentToken).toBe("stale-token");
        return new Response(JSON.stringify({ error: "Invalid or missing CSRF token." }), {
          status: 403,
        });
      }

      expect(sentToken).toBe("refreshed-token");
      return new Response(JSON.stringify({ ok: true }), { status: 200 });
    });

    vi.stubGlobal("fetch", fetchMock);

    const res = await apiFetch<{ ok: boolean }>("/api/test", { method: "POST", body: {} });
    expect(res).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("throws ApiError with violations on 422 validation failure", async () => {
    const fetchMock = vi.fn(async () =>
      new Response(
        JSON.stringify({
          error: "A valid email address is required.",
          violations: [{ propertyPath: "email", message: "A valid email address is required." }],
        }),
        { status: 422 },
      ),
    );
    vi.stubGlobal("fetch", fetchMock);

    document.cookie = "XSRF-TOKEN=abc; path=/";

    await expect(
      apiFetch("/api/auth/magic-link", { method: "POST", body: { email: "bad" } }),
    ).rejects.toMatchObject({
      name: "ApiError",
      status: 422,
      message: "A valid email address is required.",
      violations: [{ propertyPath: "email", message: "A valid email address is required." }],
    });

    try {
      await apiFetch("/api/auth/magic-link", { method: "POST", body: { email: "bad" } });
    } catch (err) {
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).getFieldError("email")).toBe("A valid email address is required.");
    }
  });

  it("deduplicates concurrent CSRF bootstrap calls across parallel mutations", async () => {
    let csrfCalls = 0;

    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url === "/api/auth/csrf") {
        csrfCalls += 1;
        await new Promise((r) => setTimeout(r, 10));
        document.cookie = "XSRF-TOKEN=shared-token; path=/";
        return new Response(null, { status: 204 });
      }
      return new Response(JSON.stringify({ ok: true }), { status: 200 });
    });
    vi.stubGlobal("fetch", fetchMock);

    await Promise.all([
      apiFetch("/api/a", { method: "POST", body: {} }),
      apiFetch("/api/b", { method: "POST", body: {} }),
    ]);

    expect(csrfCalls).toBe(1);
  });

  it("does not retry CSRF on non-CSRF 403 Forbidden errors", async () => {
    document.cookie = "XSRF-TOKEN=valid-token; path=/";

    const fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ error: "Access denied." }), { status: 403 }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(apiFetch("/api/admin-only", { method: "POST", body: {} })).rejects.toMatchObject({
      status: 403,
      message: "Access denied.",
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("preserves browser boundary for FormData without setting Content-Type", async () => {
    document.cookie = "XSRF-TOKEN=valid-token; path=/";

    const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      const headers = new Headers(init?.headers);
      expect(headers.has("Content-Type")).toBe(false);
      expect(init?.body).toBeInstanceOf(FormData);
      return new Response(JSON.stringify({ id: "photo-1" }), { status: 201 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const form = new FormData();
    form.append("file", new Blob(["abc"], { type: "image/jpeg" }), "test.jpg");

    const res = await apiFetch<{ id: string }>("/api/pois/1/photos", {
      method: "POST",
      body: form,
    });
    expect(res).toEqual({ id: "photo-1" });
  });
});
