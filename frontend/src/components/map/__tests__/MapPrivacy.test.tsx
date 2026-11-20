import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { Router } from "wouter";
import { memoryLocation } from "wouter/memory-location";
import { AppRoutes } from "../../../App";
import { AuthProvider } from "../../../context/AuthContext";

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

describe("Map Privacy & Google Maps Opt-In", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    document
      .querySelectorAll('script[src*="maps.googleapis.com"]')
      .forEach((el) => el.remove());
  });

  it("never preloads Google Maps SDK even when googleMapsEnabled=true until user consents", async () => {
    document.cookie = "XSRF-TOKEN=test-xsrf; path=/";
    vi.stubGlobal(
      "fetch",
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);
        if (url === "/api/config") {
          return jsonResponse({
            googleMapsEnabled: true,
            googleMapsApiKey: "fake-api-key",
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
      }),
    );

    const { hook } = memoryLocation({ path: "/", record: true });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <Router hook={hook}>
            <AppRoutes />
          </Router>
        </AuthProvider>
      </QueryClientProvider>,
    );

    const toggleBtn = await screen.findByRole("button", { name: /^maplibre$/i });
    expect(document.querySelector('script[src*="maps.googleapis.com"]')).toBeNull();

    fireEvent.click(toggleBtn);
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    expect(document.querySelector('script[src*="maps.googleapis.com"]')).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: /stay on maplibre/i }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(document.querySelector('script[src*="maps.googleapis.com"]')).toBeNull();

    fireEvent.click(toggleBtn);
    fireEvent.click(await screen.findByRole("button", { name: /enable google maps/i }));

    await waitFor(() => {
      const script = document.querySelector<HTMLScriptElement>(
        'script[src*="maps.googleapis.com"]',
      );
      expect(script).not.toBeNull();
      expect(script?.src).toContain("key=fake-api-key");
    });
  });
});
