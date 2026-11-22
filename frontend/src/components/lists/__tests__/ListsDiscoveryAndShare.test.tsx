import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { Router } from "wouter";
import { memoryLocation } from "wouter/memory-location";
import { AppRoutes } from "../../../App";
import { AuthProvider } from "../../../context/AuthContext";

const fitBoundsMock = vi.fn();

vi.mock("maplibre-gl", () => {
  class MockMarker {
    setLngLat() {
      return this;
    }
    addTo() {
      return this;
    }
    remove() {}
  }
  class MockNavigationControl {}
  class MockLngLatBounds {
    extend() {
      return this;
    }
  }
  class MockMap {
    on() {}
    addControl() {}
    remove() {}
    flyTo() {}
    fitBounds(bounds: unknown, opts: unknown) {
      fitBoundsMock(bounds, opts);
    }
    getZoom() {
      return 12;
    }
    getCanvas() {
      return { style: {} };
    }
    getBounds() {
      return {
        getWest: () => 2.2,
        getSouth: () => 48.8,
        getEast: () => 2.5,
        getNorth: () => 48.9,
      };
    }
  }
  return {
    LngLatBounds: MockLngLatBounds,
    Map: MockMap,
    Marker: MockMarker,
    NavigationControl: MockNavigationControl,
    addProtocol: vi.fn(),
    removeProtocol: vi.fn(),
    setWorkerUrl: vi.fn(),
  };
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

const LIST_1 = {
  id: "11111111-1111-7111-8111-111111111111",
  name: "Bakeries",
  color: "#E11D48",
  icon: "🥐",
  ownerId: "01950000-0000-7000-8000-000000000002",
  ownerName: "Alex",
};

const LIST_2 = {
  id: "22222222-2222-7222-8222-222222222222",
  name: "Coffee",
  color: "#059669",
  icon: "☕",
  ownerId: "01950000-0000-7000-8000-000000000999",
  ownerName: "Sam",
};

const LIST_3 = {
  id: "33333333-3333-7333-8333-333333333333",
  name: "Lisbon Trip",
  color: "#4F46E5",
  icon: "📍",
  ownerId: "01950000-0000-7000-8000-000000000999",
  ownerName: "Sam",
};

const MEMBER_USER = {
  id: "01950000-0000-7000-8000-000000000002",
  displayName: "Alex",
  email: "alex@example.com",
  roles: ["ROLE_USER"],
};

const POIS = [
  {
    id: "aaaa1111-1111-7111-8111-111111111111",
    name: "Du Pain et des Idées",
    latitude: 48.871,
    longitude: 2.362,
    address: "34 Rue Yves Toudic",
    isPublic: false,
    version: 1,
    lists: [LIST_1],
  },
  {
    id: "bbbb2222-2222-7222-8222-222222222222",
    name: "Boot Café",
    latitude: 48.863,
    longitude: 2.363,
    address: "19 Rue du Pont aux Choux",
    isPublic: true,
    version: 1,
    lists: [LIST_2],
  },
];

function renderListsWorkspace(
  fetchImpl: (url: string, init?: RequestInit) => Promise<Response>,
  initialPath = "/",
) {
  Object.defineProperty(window, "matchMedia", {
    writable: true,
    configurable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: true,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });

  document.cookie = "XSRF-TOKEN=test-xsrf; path=/";
  vi.stubGlobal(
    "fetch",
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => fetchImpl(String(input), init)),
  );

  const { hook } = memoryLocation({ path: initialPath, record: true });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Router hook={hook}>
          <AppRoutes />
        </Router>
      </AuthProvider>
    </QueryClientProvider>,
  );
}

describe("Lists Discovery, CRUD & Shareable View", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    fitBoundsMock.mockClear();
  });

  it("browses, scope-filters, and opens lists directly from the Lists tab without filter pills", async () => {
    renderListsWorkspace(async (url) => {
      if (url === "/api/config") {
        return jsonResponse({
          googleMapsEnabled: false,
          googleMapsApiKey: null,
          defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
          pmtilesUrl: null,
          geocoderProvider: "photon",
          publicBrowsingEnabled: true,
        });
      }
      if (url === "/api/auth/me") return jsonResponse(MEMBER_USER);
      if (url === "/api/lists") return jsonResponse([LIST_1, LIST_2, LIST_3]);
      if (url.startsWith("/api/pois")) {
        return jsonResponse(POIS);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    expect(screen.queryByRole("group", { name: /filter by lists/i })).not.toBeInTheDocument();

    fireEvent.click(await screen.findByRole("tab", { name: /^lists$/i }));

    const listCollection = await screen.findByRole("list", { name: /curated lists/i });
    expect(within(listCollection).getByText("Bakeries")).toBeInTheDocument();
    expect(within(listCollection).getByText("Coffee")).toBeInTheDocument();
    expect(within(listCollection).getByText("Lisbon Trip")).toBeInTheDocument();

    expect(within(listCollection).getByRole("link", { name: /open bakeries/i })).toHaveAttribute(
      "href",
      `/lists/${LIST_1.id}`,
    );
    expect(within(listCollection).getByRole("button", { name: /edit bakeries/i })).toBeInTheDocument();
    expect(within(listCollection).queryByRole("button", { name: /edit coffee/i })).not.toBeInTheDocument();
    expect(within(listCollection).queryByRole("button", { name: /filter by/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /^mine$/i }));
    expect(within(listCollection).getByText("Bakeries")).toBeInTheDocument();
    expect(within(listCollection).queryByText("Coffee")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /^all$/i }));
    fireEvent.click(screen.getByRole("button", { name: /in map area/i }));
    expect(within(listCollection).getByText("Bakeries")).toBeInTheDocument();
    expect(within(listCollection).queryByText("Lisbon Trip")).not.toBeInTheDocument();

    expect(screen.queryByRole("searchbox", { name: /filter lists/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /add place/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /add list/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /clear list filter/i })).not.toBeInTheDocument();
  });

  it("renders shareable /lists/:id view without bbox and auto-fits map bounds", async () => {
    const poiUrls: string[] = [];

    renderListsWorkspace(
      async (url) => {
        if (url === "/api/config") {
          return jsonResponse({
            googleMapsEnabled: false,
            googleMapsApiKey: null,
            defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
            pmtilesUrl: null,
            geocoderProvider: "photon",
            publicBrowsingEnabled: true,
          });
        }
        if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
        if (url === "/api/lists") return jsonResponse([LIST_1, LIST_2]);
        if (url.startsWith("/api/pois")) {
          poiUrls.push(url);
          return jsonResponse(POIS);
        }
        return jsonResponse({ error: "Not found" }, 404);
      },
      `/lists/${LIST_1.id}`,
    );

    expect(await screen.findByRole("heading", { name: "Bakeries" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /lists/i })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("link", { name: /all lists/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copy share link/i })).toBeInTheDocument();

    expect(poiUrls.some((u) => u === `/api/pois?listIds=${LIST_1.id}`)).toBe(true);
    await waitFor(() => {
      expect(fitBoundsMock).toHaveBeenCalledTimes(1);
    });
  });

  it("creates a single list in Create List mode with offline emoji picker and flag search", async () => {
    let lists = [LIST_1, LIST_2];
    const postedIcons: string[] = [];

    renderListsWorkspace(async (url, init) => {
      if (url === "/api/config") {
        return jsonResponse({
          googleMapsEnabled: false,
          googleMapsApiKey: null,
          defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
          pmtilesUrl: null,
          geocoderProvider: "photon",
          publicBrowsingEnabled: true,
        });
      }
      if (url === "/api/auth/me") return jsonResponse(MEMBER_USER);
      if (url === "/api/lists" && (!init?.method || init.method === "GET")) {
        return jsonResponse(lists);
      }
      if (url === "/api/lists" && init?.method === "POST") {
        const body = JSON.parse(String(init.body)) as { name: string; color: string; icon: string };
        postedIcons.push(body.icon);
        const created = {
          id: "44444444-4444-7444-8444-444444444444",
          name: body.name,
          color: body.color,
          icon: body.icon,
          ownerId: MEMBER_USER.id,
          ownerName: MEMBER_USER.displayName,
        };
        lists = [...lists, created];
        return jsonResponse(created, 201);
      }
      if (url.startsWith("/api/pois")) return jsonResponse(POIS);
      return jsonResponse({ error: "Not found" }, 404);
    });

    fireEvent.click(await screen.findByRole("tab", { name: /^lists$/i }));
    fireEvent.click(await screen.findByRole("button", { name: /add list/i }));
    expect(await screen.findByRole("heading", { name: /create list/i })).toBeInTheDocument();
    expect(screen.queryByRole("list", { name: /existing lists/i })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/list name/i), { target: { value: "Portugal" } });
    fireEvent.change(screen.getByRole("searchbox", { name: /search emojis/i }), {
      target: { value: "portugal" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Select emoji 🇵🇹" }));
    fireEvent.click(screen.getByRole("button", { name: /create list/i }));

    expect((await screen.findAllByText("Portugal")).length).toBeGreaterThan(0);
    expect(postedIcons).toEqual(["🇵🇹"]);
  });

  it("edits and deletes a single list with inline delete confirmation", async () => {
    let lists = [LIST_1, LIST_2];
    const deletedIds: string[] = [];

    renderListsWorkspace(async (url, init) => {
      if (url === "/api/config") {
        return jsonResponse({
          googleMapsEnabled: false,
          googleMapsApiKey: null,
          defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
          pmtilesUrl: null,
          geocoderProvider: "photon",
          publicBrowsingEnabled: true,
        });
      }
      if (url === "/api/auth/me") return jsonResponse(MEMBER_USER);
      if (url === "/api/lists" && (!init?.method || init.method === "GET")) {
        return jsonResponse(lists);
      }
      if (url === `/api/lists/${LIST_1.id}` && init?.method === "DELETE") {
        deletedIds.push(LIST_1.id);
        lists = lists.filter((l) => l.id !== LIST_1.id);
        return new Response(null, { status: 204 });
      }
      if (url.startsWith("/api/pois")) return jsonResponse(POIS);
      return jsonResponse({ error: "Not found" }, 404);
    });

    fireEvent.click(await screen.findByRole("tab", { name: /^lists$/i }));
    fireEvent.click(await screen.findByRole("button", { name: /edit bakeries/i }));
    expect(await screen.findByRole("heading", { name: /edit list/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /save changes/i })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /delete list/i }));
    expect(screen.queryByRole("button", { name: /save changes/i })).not.toBeInTheDocument();
    expect(screen.getByText(/delete this list\? spots stay on the map\./i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /^confirm$/i }));
    await waitFor(() => {
      expect(deletedIds).toEqual([LIST_1.id]);
    });
    expect(screen.queryByRole("heading", { name: /edit list/i })).not.toBeInTheDocument();
  });
});
