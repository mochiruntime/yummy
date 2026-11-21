import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { Router } from "wouter";
import { memoryLocation } from "wouter/memory-location";
import { AppRoutes } from "../../../App";
import { AuthProvider } from "../../../context/AuthContext";

const flyToMock = vi.fn();

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
    flyTo(opts: unknown) {
      flyToMock(opts);
    }
    fitBounds() {}
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

function renderShell(
  fetchImpl: (url: string) => Promise<Response>,
  isDesktop: boolean,
) {
  Object.defineProperty(window, "matchMedia", {
    writable: true,
    configurable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: isDesktop,
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
    vi.fn((input: RequestInfo | URL) => fetchImpl(String(input))),
  );

  const { hook } = memoryLocation({ path: "/", record: true });
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

describe("MapWorkspace Shell & Geocode Search", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    flyToMock.mockClear();
  });

  it("cycles mobile bottom-sheet snap points (peek -> half -> full -> peek)", async () => {
    renderShell(async (url) => {
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
      if (url === "/api/lists") return jsonResponse([]);
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      return jsonResponse({ error: "Not found" }, 404);
    }, false);

    const sheet = await screen.findByLabelText(/places sheet/i);
    expect(sheet).toHaveAttribute("data-snap", "peek");

    const handle = screen.getByRole("button", { name: /cycle sheet height/i });
    fireEvent.click(handle);
    expect(sheet).toHaveAttribute("data-snap", "half");

    fireEvent.click(handle);
    expect(sheet).toHaveAttribute("data-snap", "full");

    fireEvent.click(handle);
    expect(sheet).toHaveAttribute("data-snap", "peek");
  });

  it("displays discriminating address subtitles in geocode suggestions and flies map on select", async () => {
    renderShell(async (url) => {
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
      if (url === "/api/auth/me") {
        return jsonResponse({
          id: "01950000-0000-7000-8000-000000000002",
          displayName: "Alex",
          email: "alex@example.com",
          roles: ["ROLE_USER"],
        });
      }
      if (url === "/api/lists") return jsonResponse([]);
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      if (url.startsWith("/api/geocode")) {
        return jsonResponse([
          {
            label: "Lausanne",
            address: "Vaud, Switzerland",
            latitude: 46.5197,
            longitude: 6.6323,
          },
          {
            label: "Lausanne",
            address: "Québec, Canada",
            latitude: 48.1,
            longitude: -70.2,
          },
        ]);
      }
      return jsonResponse({ error: "Not found" }, 404);
    }, true);

    const searchInput = await screen.findByRole("searchbox", { name: /search places/i });
    fireEvent.change(searchInput, { target: { value: "Lausanne" } });

    expect(await screen.findByText("Vaud, Switzerland")).toBeInTheDocument();
    expect(screen.getByText("Québec, Canada")).toBeInTheDocument();

    fireEvent.click(screen.getByText("Vaud, Switzerland"));
    expect(flyToMock).toHaveBeenCalledWith({
      center: [6.6323, 46.5197],
      zoom: 15,
    });
  });

  it("allows unauthenticated guests to use geocode autocomplete and displays 429 rate-limit notice", async () => {
    let calls = 0;

    renderShell(async (url) => {
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
      if (url === "/api/lists") return jsonResponse([]);
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      if (url.startsWith("/api/geocode")) {
        calls += 1;
        if (calls === 1) {
          return jsonResponse([
            {
              label: "Bastille",
              address: "Paris, France",
              latitude: 48.853,
              longitude: 2.369,
            },
          ]);
        }
        return jsonResponse({ error: "Too many search requests." }, 429);
      }
      return jsonResponse({ error: "Not found" }, 404);
    }, true);

    const searchInput = await screen.findByRole("searchbox", { name: /search places/i });
    fireEvent.change(searchInput, { target: { value: "Bastille" } });

    expect(await screen.findByText("Paris, France")).toBeInTheDocument();

    fireEvent.change(searchInput, { target: { value: "Marais" } });
    expect(await screen.findByText(/too many searches/i)).toBeInTheDocument();
  });

  it("renders heterogeneous global search results for spots, lists, and addresses simultaneously", async () => {
    const spotId = "11111111-1111-7111-8111-111111111111";
    const listId = "22222222-2222-7222-8222-222222222222";

    renderShell(async (url) => {
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
      if (url === "/api/lists") {
        return jsonResponse([
          {
            id: listId,
            name: "Croissant Trail",
            color: "#E11D48",
            icon: "🥐",
            isPublic: true,
            ownerId: "33333333-3333-7333-8333-333333333333",
            ownerName: "Alex",
            createdAt: "2026-11-20T00:00:00+00:00",
          },
        ]);
      }
      if (url.startsWith("/api/pois?") && url.includes("q=Croissant")) {
        return jsonResponse([
          {
            id: spotId,
            createdById: "33333333-3333-7333-8333-333333333333",
            name: "Maison Croissant",
            address: "Rue Oberkampf, Paris",
            latitude: 48.865,
            longitude: 2.378,
            isPublic: true,
            version: 1,
            lists: [],
          },
        ]);
      }
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      if (url.startsWith("/api/geocode")) {
        return jsonResponse([
          {
            label: "Croissant Street",
            address: "Lyon, France",
            latitude: 45.764,
            longitude: 4.835,
          },
        ]);
      }
      return jsonResponse({ error: "Not found" }, 404);
    }, true);

    const searchInput = await screen.findByRole("searchbox", { name: /search places/i });
    fireEvent.change(searchInput, { target: { value: "Croissant" } });

    const spotsGroup = await screen.findByRole("list", { name: /matching spots/i });
    const listsGroup = await screen.findByRole("list", { name: /matching lists/i });
    const addressesGroup = await screen.findByRole("list", { name: /address suggestions/i });

    expect(spotsGroup).toHaveTextContent("Maison Croissant");
    expect(listsGroup).toHaveTextContent("Croissant Trail");
    expect(addressesGroup).toHaveTextContent("Croissant Street");

    // Clicking a list suggestion navigates to /lists/:id
    fireEvent.click(screen.getByRole("button", { name: /croissant trail/i }));
    expect(await screen.findByRole("heading", { name: "Croissant Trail" })).toBeInTheDocument();
  });
});
