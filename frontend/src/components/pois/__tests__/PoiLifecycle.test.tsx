import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { Router } from "wouter";
import { memoryLocation } from "wouter/memory-location";
import { AppRoutes } from "../../../App";
import { AuthProvider } from "../../../context/AuthContext";

type MapClickHandler = (event: { lngLat: { lat: number; lng: number } }) => void;

const mapListeners: Record<string, MapClickHandler[]> = {};

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
    on(event: string, cb: MapClickHandler) {
      mapListeners[event] = [...(mapListeners[event] ?? []), cb];
    }
    addControl() {}
    remove() {}
    flyTo() {}
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

const CREATOR_USER = {
  id: "01950000-0000-7000-8000-000000000002",
  displayName: "Alex",
  email: "alex@example.com",
  roles: ["ROLE_USER"],
};

const OTHER_USER = {
  id: "01950000-0000-7000-8000-000000000999",
  displayName: "Sam",
  email: "sam@example.com",
  roles: ["ROLE_USER"],
};

const OWNED_LIST = {
  id: "11111111-1111-7111-8111-111111111111",
  name: "Bakeries",
  color: "#E11D48",
  icon: "🥐",
  ownerId: CREATOR_USER.id,
  ownerName: "Alex",
};

const OTHER_LIST = {
  id: "22222222-2222-7222-8222-222222222222",
  name: "Sam Coffee",
  color: "#059669",
  icon: "☕",
  ownerId: OTHER_USER.id,
  ownerName: "Sam",
};

const POI_ID = "aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa";

const BASE_POI_DETAIL = {
  id: POI_ID,
  name: "Du Pain et des Idees",
  address: "34 Rue Yves Toudic, Paris",
  description: "Best pistachio escargot.",
  latitude: 48.8712,
  longitude: 2.3629,
  isPublic: false,
  version: 1,
  createdBy: { id: CREATOR_USER.id, displayName: "Alex" },
  lists: [{ id: OWNED_LIST.id, name: OWNED_LIST.name, color: OWNED_LIST.color, icon: OWNED_LIST.icon }],
};

function mockConfigResponse() {
  return jsonResponse({
    googleMapsEnabled: false,
    googleMapsApiKey: null,
    defaultMapStyleUrl: "https://tiles.openfreemap.org/styles/liberty",
    pmtilesUrl: null,
    geocoderProvider: "photon",
    publicBrowsingEnabled: true,
  });
}

function renderWorkspace(
  fetchHandler: (url: string, init?: RequestInit) => Promise<Response>,
  initialPath = "/",
) {
  document.cookie = "XSRF-TOKEN=test-csrf-token; path=/";
  vi.spyOn(globalThis, "fetch").mockImplementation((input, init) => {
    const url = typeof input === "string" ? input : input instanceof Request ? input.url : "";
    if (url === "/api/auth/csrf") {
      return Promise.resolve(new Response(null, { status: 204 }));
    }
    return fetchHandler(url, init);
  });

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const loc = memoryLocation({ path: initialPath, record: true });

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Router hook={loc.hook}>
          <AppRoutes />
        </Router>
      </AuthProvider>
    </QueryClientProvider>,
  );
}

describe("POI Creation, Detail, Optimistic Editing & Revision History", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    Object.keys(mapListeners).forEach((key) => {
      delete mapListeners[key];
    });
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: query.includes("min-width: 768px"),
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
  });

  it("creates a POI via + Add place with geocode prefill, owned list filter, and private default", async () => {
    let postedBody: Record<string, unknown> | null = null;
    const geocodeUrls: string[] = [];

    renderWorkspace(async (url, init) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/lists") return jsonResponse([OWNED_LIST, OTHER_LIST]);
      if (url.startsWith("/api/geocode")) {
        geocodeUrls.push(url);
        return jsonResponse([
          {
            label: "Mamiche, 45 Rue Condorcet, Paris",
            name: "Mamiche",
            address: "45 Rue Condorcet, Paris",
            latitude: 48.8805,
            longitude: 2.3412,
          },
        ]);
      }
      if (url === "/api/pois" && init?.method === "POST") {
        postedBody = JSON.parse(String(init.body)) as Record<string, unknown>;
        return jsonResponse(
          {
            ...BASE_POI_DETAIL,
            id: "bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb",
            name: postedBody.name,
            address: postedBody.address,
            latitude: postedBody.latitude,
            longitude: postedBody.longitude,
            isPublic: postedBody.isPublic,
          },
          201,
        );
      }
      if (url === "/api/pois/bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb") {
        return jsonResponse({
          ...BASE_POI_DETAIL,
          id: "bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb",
          name: "Mamiche",
        });
      }
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      return jsonResponse({ error: "Not found" }, 404);
    });

    const addPlaceBtn = await screen.findByRole("button", { name: /add place/i });
    mapListeners.load?.forEach((cb) => cb({ lngLat: { lat: 48.85, lng: 2.35 } }));
    fireEvent.click(addPlaceBtn);
    expect(await screen.findByRole("heading", { name: /add place/i })).toBeInTheDocument();

    // Open ListMultiSelect combobox: only owned lists appear (Sam Coffee is excluded)
    const listCombobox = screen.getByRole("combobox", { name: /add to your lists/i });
    fireEvent.focus(listCombobox);
    expect(screen.getByRole("option", { name: /bakeries/i })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: /sam coffee/i })).not.toBeInTheDocument();

    // Select Bakeries via combobox option
    fireEvent.click(screen.getByRole("option", { name: /bakeries/i }));
    expect(screen.getByRole("button", { name: /remove bakeries/i })).toBeInTheDocument();

    // Test Find location search + clear X control + proximity lat/lng
    const locationInput = screen.getByPlaceholderText(/where’s the magic happening/i);
    fireEvent.change(locationInput, { target: { value: "Typo" } });
    fireEvent.click(screen.getByRole("button", { name: /clear location search/i }));
    expect(locationInput).toHaveValue("");

    fireEvent.change(locationInput, { target: { value: "Mamiche" } });
    const suggestion = await screen.findByRole("button", { name: /mamiche/i });
    fireEvent.click(suggestion);

    expect(geocodeUrls.some((u) => u.includes("lat=48.85") && u.includes("lng=2.35"))).toBe(true);
    expect(screen.getByLabelText(/^name/i)).toHaveValue("Mamiche, 45 Rue Condorcet, Paris");
    fireEvent.click(screen.getByRole("button", { name: /save place/i }));

    await waitFor(() => {
      expect(postedBody).toMatchObject({
        name: "Mamiche, 45 Rue Condorcet, Paris",
        address: "45 Rue Condorcet, Paris",
        latitude: 48.8805,
        longitude: 2.3412,
        isPublic: false,
        listIds: [OWNED_LIST.id],
      });
    });
  });

  it("supports Pick on map pin-drop flow and re-opens CreatePoiModal with clicked coordinates", async () => {
    renderWorkspace(async (url) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      return jsonResponse({ error: "Not found" }, 404);
    });

    fireEvent.click(await screen.findByRole("button", { name: /add place/i }));
    fireEvent.click(await screen.findByRole("button", { name: /pick on map/i }));

    expect(await screen.findByText(/tap anywhere on the map/i)).toBeInTheDocument();
    expect(mapListeners.click?.length).toBeGreaterThan(0);

    // Simulate user clicking the MapLibre canvas
    mapListeners.click[0]({ lngLat: { lat: 48.85661, lng: 2.35222 } });

    expect(await screen.findByRole("heading", { name: /add place/i })).toBeInTheDocument();
    expect(screen.getByText(/coordinates: 48\.85661, 2\.35222/i)).toBeInTheDocument();
  });

  it("renders Spot not found for 404 /pois/:id", async () => {
    renderWorkspace(
      async (url) => {
        if (url === "/api/config") return mockConfigResponse();
        if (url === "/api/auth/me") return jsonResponse({ error: "Unauthorized" }, 401);
        if (url === "/api/lists") return jsonResponse([]);
        if (url === "/api/pois/not-found") return jsonResponse({ error: "Not found" }, 404);
        if (url.startsWith("/api/pois")) return jsonResponse([]);
        return jsonResponse({ error: "Not found" }, 404);
      },
      "/pois/not-found",
    );

    expect(await screen.findByText(/spot not found\./i)).toBeInTheDocument();
  });

  it("supports visibility toggle, owned list toggles, and inline delete", async () => {
    let poi = { ...BASE_POI_DETAIL };
    let deleted = false;

    renderWorkspace(
      async (url, init) => {
        if (url === "/api/config") return mockConfigResponse();
        if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
        if (url === "/api/lists") return jsonResponse([OWNED_LIST, OTHER_LIST]);
        if (url === `/api/pois/${POI_ID}` && (!init?.method || init.method === "GET")) {
          return deleted ? jsonResponse({ error: "Not found" }, 404) : jsonResponse(poi);
        }
        if (url === `/api/pois/${POI_ID}/visibility` && init?.method === "PATCH") {
          const body = JSON.parse(String(init.body)) as { isPublic: boolean };
          poi = { ...poi, isPublic: body.isPublic };
          return jsonResponse(poi);
        }
        if (url === `/api/lists/${OWNED_LIST.id}/pois/${POI_ID}` && init?.method === "DELETE") {
          poi = { ...poi, lists: [] };
          return new Response(null, { status: 204 });
        }
        if (url === `/api/pois/${POI_ID}` && init?.method === "DELETE") {
          deleted = true;
          return new Response(null, { status: 204 });
        }
        if (url.startsWith("/api/pois")) return jsonResponse(deleted ? [] : [poi]);
        return jsonResponse({ error: "Not found" }, 404);
      },
      `/pois/${POI_ID}`,
    );

    expect(await screen.findByRole("heading", { name: poi.name })).toBeInTheDocument();

    // Toggle visibility from Friends Only -> Public
    fireEvent.click(screen.getByRole("button", { name: /make public/i }));
    expect(await screen.findByRole("button", { name: /make friends only/i })).toBeInTheDocument();

    // Remove from owned list Bakeries inside the right detail sidebar
    const detailSidebar = screen.getByRole("complementary", { name: /spot details/i });
    fireEvent.click(within(detailSidebar).getByRole("button", { name: /bakeries/i }));
    await waitFor(() => {
      expect(poi.lists).toHaveLength(0);
    });

    // Inline delete confirmation (no window.confirm)
    fireEvent.click(screen.getByRole("button", { name: /^delete$/i }));
    fireEvent.click(await screen.findByRole("button", { name: /confirm delete/i }));
    await waitFor(() => {
      expect(deleted).toBe(true);
    });
  });

  it("handles 409 Conflict with Reload latest version and supports revision history + revert", async () => {
    let serverDetail = { ...BASE_POI_DETAIL, version: 1 };
    let patchCount = 0;

    renderWorkspace(
      async (url, init) => {
        if (url === "/api/config") return mockConfigResponse();
        if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
        if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
        if (url === `/api/pois/${POI_ID}` && (!init?.method || init.method === "GET")) {
          return jsonResponse(serverDetail);
        }
        if (url === `/api/pois/${POI_ID}` && init?.method === "PATCH") {
          patchCount += 1;
          if (patchCount === 1) {
            // Simulate concurrent update on server before returning 409
            serverDetail = {
              ...serverDetail,
              version: 2,
              description: "Updated by Sam while Alex was editing.",
            };
            return jsonResponse({ error: "Conflict: POI was modified" }, 409);
          }
          const body = JSON.parse(String(init.body)) as { version: number; description?: string };
          serverDetail = {
            ...serverDetail,
            version: body.version + 1,
            description: body.description ?? serverDetail.description,
          };
          return jsonResponse(serverDetail);
        }
        if (url === `/api/pois/${POI_ID}/revisions`) {
          return jsonResponse([
            {
              revisionNumber: 2,
              editorName: "Sam",
              changedFields: { description: "Updated by Sam" },
              createdAt: "2026-11-20T12:00:00Z",
            },
            {
              revisionNumber: 1,
              editorName: "Alex",
              changedFields: { name: "Du Pain et des Idees" },
              createdAt: "2026-11-20T11:00:00Z",
            },
          ]);
        }
        if (url === `/api/pois/${POI_ID}/revisions/1/revert` && init?.method === "POST") {
          serverDetail = {
            ...serverDetail,
            version: 3,
            description: "Best pistachio escargot.",
          };
          return jsonResponse(serverDetail);
        }
        if (url.startsWith("/api/pois")) return jsonResponse([serverDetail]);
        return jsonResponse({ error: "Not found" }, 404);
      },
      `/pois/${POI_ID}`,
    );

    fireEvent.click(await screen.findByRole("button", { name: /^edit$/i }));
    expect(await screen.findByRole("heading", { name: /edit du pain et des idees/i })).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/description/i), {
      target: { value: "My stale edit" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save changes/i }));

    // 409 Conflict banner appears with Reload latest version button
    expect(await screen.findByRole("alert")).toHaveTextContent(/conflict: poi was modified/i);
    fireEvent.click(screen.getByRole("button", { name: /reload latest version/i }));

    await waitFor(() => {
      expect(screen.getByLabelText(/description/i)).toHaveValue(
        "Updated by Sam while Alex was editing.",
      );
    });

    // Save again with updated version -> succeeds and closes modal
    fireEvent.click(screen.getByRole("button", { name: /save changes/i }));
    await waitFor(() => {
      expect(screen.queryByRole("heading", { name: /edit du pain et des idees/i })).not.toBeInTheDocument();
    });

    // Open History modal and revert to v1
    fireEvent.click(screen.getByRole("button", { name: /^history$/i }));
    expect(await screen.findByRole("heading", { name: /history · du pain et des idees/i })).toBeInTheDocument();

    fireEvent.click(await screen.findByRole("button", { name: /revert to v1/i }));
    await waitFor(() => {
      expect(serverDetail.version).toBe(3);
      expect(screen.queryByRole("heading", { name: /revision history/i })).not.toBeInTheDocument();
    });
  });

  it("submits an optional inline review with dish breakdown from CreatePoiModal and renders reviews in PoiDetailPanel", async () => {
    let postedReviewBody: Record<string, unknown> | null = null;
    const createdPoiId = "cccccccc-cccc-7ccc-8ccc-cccccccccccc";
    const createdPoiWithReview = {
      ...BASE_POI_DETAIL,
      id: createdPoiId,
      name: "Boulangerie Test",
      reviews: [
        {
          id: "dddddddd-dddd-7ddd-8ddd-dddddddddddd",
          authorName: "Alex",
          rating: 5,
          body: "Fresh out of the oven!",
          isPublic: false,
          priceLevel: 2,
          currency: "CHF",
          vibes: ["🔥 Must-order"],
          items: [
            {
              id: "eeeeeeee-eeee-7eee-8eee-eeeeeeeeeeee",
              name: "Croissant",
              verdict: "must_get",
              priceAmount: "3.50",
              note: "Crispy layers",
              sortOrder: 0,
            },
          ],
          createdAt: "2026-11-20T12:00:00Z",
          reactions: [],
        },
      ],
      photos: [],
    };

    renderWorkspace(async (url, init) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
      if (url === "/api/pois" && init?.method === "POST") {
        return jsonResponse(createdPoiWithReview, 201);
      }
      if (url === `/api/pois/${createdPoiId}/reviews` && init?.method === "POST") {
        postedReviewBody = JSON.parse(String(init.body)) as Record<string, unknown>;
        return jsonResponse(createdPoiWithReview.reviews[0], 201);
      }
      if (url === `/api/pois/${createdPoiId}`) {
        return jsonResponse(createdPoiWithReview);
      }
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      return jsonResponse({ error: "Not found" }, 404);
    });

    const addPlaceBtn = await screen.findByRole("button", { name: /add place/i });
    mapListeners.load?.forEach((cb) => cb({ lngLat: { lat: 48.85, lng: 2.35 } }));
    fireEvent.click(addPlaceBtn);

    fireEvent.click(screen.getByRole("button", { name: /pick on map/i }));
    mapListeners.click[0]({ lngLat: { lat: 48.85661, lng: 2.35222 } });

    fireEvent.change(await screen.findByLabelText(/^name/i), { target: { value: "Boulangerie Test" } });
    fireEvent.click(screen.getByRole("button", { name: /rate 5 stars/i }));
    fireEvent.click(screen.getByRole("button", { name: "$$" }));
    fireEvent.click(screen.getByRole("button", { name: /must-order/i }));
    fireEvent.change(screen.getByLabelText(/review notes/i), {
      target: { value: "Fresh out of the oven!" },
    });
    fireEvent.click(screen.getByRole("button", { name: /add dish \/ item you ordered/i }));
    fireEvent.change(screen.getByLabelText(/dish or drink name/i), {
      target: { value: "Croissant" },
    });
    fireEvent.change(screen.getByLabelText(/item price/i), { target: { value: "3.50" } });

    fireEvent.click(screen.getByRole("button", { name: /save place & 5★ review/i }));

    await waitFor(() => {
      expect(postedReviewBody).toMatchObject({
        rating: 5,
        body: "Fresh out of the oven!",
        priceLevel: 2,
        currency: "CHF",
        vibes: ["🔥 Must-order"],
        items: [{ name: "Croissant", verdict: "must_get", priceAmount: "3.50", note: null }],
      });
    });

    expect(await screen.findByText("Croissant")).toBeInTheDocument();
    expect(screen.getByText(/fresh out of the oven!/i)).toBeInTheDocument();
  });

  it("retries only failed photo uploads without duplicating the POI or review", async () => {
    const createdPoiId = "55555555-5555-7555-8555-555555555555";
    const createdReviewId = "66666666-6666-7666-8666-666666666666";
    let poiPostCount = 0;
    let reviewPostCount = 0;
    let photoPostCount = 0;

    const createdPoiDetail = {
      ...BASE_POI_DETAIL,
      id: createdPoiId,
      name: "Retry Spot",
      reviews: [
        {
          id: createdReviewId,
          authorId: CREATOR_USER.id,
          authorName: CREATOR_USER.displayName,
          rating: 4,
          body: null,
          isPublic: false,
          priceLevel: null,
          currency: null,
          vibes: [],
          items: [],
          reactions: [],
          createdAt: "2026-11-20T12:00:00+00:00",
        },
      ],
      photos: [],
    };

    renderWorkspace(async (url, init) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/auth/csrf") return new Response(null, { status: 204 });
      if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
      if (url === "/api/pois" && init?.method === "POST") {
        poiPostCount += 1;
        return jsonResponse(createdPoiDetail, 201);
      }
      if (url === `/api/pois/${createdPoiId}/reviews` && init?.method === "POST") {
        reviewPostCount += 1;
        return jsonResponse(createdPoiDetail.reviews[0], 201);
      }
      if (url === `/api/pois/${createdPoiId}/photos` && init?.method === "POST") {
        photoPostCount += 1;
        if (photoPostCount === 1) {
          return jsonResponse({ error: "Temporary upload error" }, 500);
        }
        return jsonResponse(
          {
            id: "77777777-7777-7777-8777-777777777777",
            url: "/uploads/full.webp",
            thumbUrl: "/uploads/thumb.webp",
            isPublic: false,
            caption: null,
            reviewId: createdReviewId,
            reviewItemId: null,
            createdAt: "2026-11-20T12:00:00+00:00",
          },
          201,
        );
      }
      if (url === `/api/pois/${createdPoiId}`) {
        return jsonResponse(createdPoiDetail);
      }
      if (url.startsWith("/api/pois")) return jsonResponse([]);
      return jsonResponse({ error: "Not found" }, 404);
    });

    fireEvent.click(await screen.findByRole("button", { name: /add place/i }));
    fireEvent.click(screen.getByRole("button", { name: /pick on map/i }));
    mapListeners.click[0]({ lngLat: { lat: 48.85661, lng: 2.35222 } });

    fireEvent.change(await screen.findByLabelText(/^name/i), { target: { value: "Retry Spot" } });
    fireEvent.click(screen.getByRole("button", { name: /rate 4 stars/i }));

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = new File(["img"], "dish.jpg", { type: "image/jpeg" });
    fireEvent.change(fileInput, { target: { files: [file] } });

    fireEvent.click(screen.getByRole("button", { name: /save place & 4★ review/i }));

    expect(await screen.findByText(/place saved! review\/photo upload failed/i)).toBeInTheDocument();
    expect(poiPostCount).toBe(1);
    expect(reviewPostCount).toBe(1);
    expect(photoPostCount).toBe(1);

    fireEvent.click(screen.getByRole("button", { name: /retry review & photos/i }));

    await waitFor(() => {
      expect(photoPostCount).toBe(2);
    });
    expect(poiPostCount).toBe(1);
    expect(reviewPostCount).toBe(1);
  });

  it("hides review moderation buttons when displayName matches but authorId differs", async () => {
    const poiWithSameNameAuthor = {
      ...BASE_POI_DETAIL,
      reviews: [
        {
          id: "88888888-8888-7888-8888-888888888888",
          authorId: OTHER_USER.id,
          authorName: CREATOR_USER.displayName,
          rating: 5,
          body: "Someone else with the same name",
          isPublic: false,
          priceLevel: null,
          currency: null,
          vibes: [],
          items: [],
          reactions: [],
          createdAt: "2026-11-20T12:00:00+00:00",
        },
      ],
    };

    renderWorkspace(
      async (url) => {
        if (url === "/api/config") return mockConfigResponse();
        if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
        if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
        if (url === `/api/pois/${POI_ID}`) return jsonResponse(poiWithSameNameAuthor);
        if (url.startsWith("/api/pois")) return jsonResponse([]);
        return jsonResponse({ error: "Not found" }, 404);
      },
      `/pois/${POI_ID}`,
    );

    expect(await screen.findByText(/someone else with the same name/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /delete review/i })).not.toBeInTheDocument();
  });

  it("opens spot details in the right desktop sidebar while keeping Spots/Lists open and highlighted on the left", async () => {
    renderWorkspace(async (url) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
      if (url === `/api/pois/${POI_ID}`) return jsonResponse(BASE_POI_DETAIL);
      if (url.startsWith("/api/pois")) {
        return jsonResponse([
          {
            id: POI_ID,
            name: BASE_POI_DETAIL.name,
            address: BASE_POI_DETAIL.address,
            latitude: BASE_POI_DETAIL.latitude,
            longitude: BASE_POI_DETAIL.longitude,
            isPublic: BASE_POI_DETAIL.isPublic,
            lists: BASE_POI_DETAIL.lists,
            createdAt: "2026-11-20T12:00:00+00:00",
          },
        ]);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    // Switch to Lists tab on the left before selecting a spot
    fireEvent.click(await screen.findByRole("tab", { name: /lists/i }));
    expect(screen.getByRole("tab", { name: /lists/i })).toHaveAttribute("aria-selected", "true");

    // Simulate clicking the spot directly (e.g. from map marker) via Spots tab
    fireEvent.click(screen.getByRole("tab", { name: /spots/i }));
    const spotCard = await screen.findByRole("button", { name: new RegExp(BASE_POI_DETAIL.name, "i") });
    fireEvent.click(spotCard);

    // Left sidebar remains mounted with aria-current="true" on the active spot
    expect(spotCard).toHaveAttribute("aria-current", "true");

    // Right sidebar renders PoiDetailPanel with Close spot details button
    const rightSidebar = screen.getByRole("complementary", { name: /spot details/i });
    expect(within(rightSidebar).getByRole("button", { name: /close spot details/i })).toBeInTheDocument();
    expect(await within(rightSidebar).findByText(BASE_POI_DETAIL.description)).toBeInTheDocument();

    // Switching left tab to Lists keeps the right detail sidebar open
    fireEvent.click(screen.getByRole("tab", { name: /lists/i }));
    expect(screen.getByRole("tab", { name: /lists/i })).toHaveAttribute("aria-selected", "true");
    expect(within(rightSidebar).getByText(BASE_POI_DETAIL.description)).toBeInTheDocument();

    // Pressing Escape closes the right sidebar
    fireEvent.keyDown(window, { key: "Escape" });
    await waitFor(() => {
      expect(rightSidebar).toHaveAttribute("aria-hidden", "true");
    });
  });

  it("supports In map area, All, and Mine scopes with paginated Load more in SpotsTabPanel", async () => {
    const requestedUrls: string[] = [];
    const makePoi = (idx: number, mine: boolean) => ({
      id: `00000000-0000-7000-8000-${String(idx).padStart(12, "0")}`,
      createdById: mine ? CREATOR_USER.id : OTHER_USER.id,
      name: `Spot #${idx}`,
      address: "Zurich",
      latitude: 47.37,
      longitude: 8.54,
      isPublic: true,
      version: 1,
      lists: [],
    });

    const page1 = Array.from({ length: 30 }, (_, i) => makePoi(i + 1, i === 0));
    const page2 = [makePoi(31, false)];

    renderWorkspace(async (url) => {
      if (url === "/api/config") return mockConfigResponse();
      if (url === "/api/auth/me") return jsonResponse(CREATOR_USER);
      if (url === "/api/lists") return jsonResponse([OWNED_LIST]);
      if (url.startsWith("/api/pois")) {
        requestedUrls.push(url);
        const params = new URLSearchParams(url.split("?")[1] ?? "");
        if (params.get("mine") === "1") {
          return jsonResponse([page1[0]]);
        }
        if (params.get("limit") === "30") {
          return jsonResponse(params.get("offset") === "30" ? page2 : page1);
        }
        return jsonResponse([page1[0]]);
      }
      return jsonResponse({ error: "Not found" }, 404);
    });

    // Default scope is "In map area"
    expect(await screen.findByRole("button", { name: /in map area/i })).toHaveAttribute(
      "aria-pressed",
      "true",
    );

    // Switch to "All" -> triggers paginated limit=30 fetch and shows Load more
    fireEvent.click(screen.getByRole("button", { name: /^all$/i }));
    expect(await screen.findByText("Spot #30")).toBeInTheDocument();
    const loadMoreBtn = await screen.findByRole("button", { name: /load more/i });
    fireEvent.click(loadMoreBtn);
    expect(await screen.findByText("Spot #31")).toBeInTheDocument();
    expect(requestedUrls.some((u) => u.includes("limit=30") && u.includes("offset=30"))).toBe(true);

    // Switch to "Mine" -> triggers mine=1 query
    fireEvent.click(screen.getByRole("button", { name: /^mine$/i }));
    await waitFor(() => {
      expect(requestedUrls.some((u) => u.includes("mine=1"))).toBe(true);
    });
    expect(await screen.findByText("Spot #1")).toBeInTheDocument();
    expect(screen.queryByText("Spot #31")).not.toBeInTheDocument();
  });
});
