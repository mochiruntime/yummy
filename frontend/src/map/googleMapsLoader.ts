let googleMapsLoadPromise: Promise<typeof google.maps> | null = null;

declare global {
  interface Window {
    google?: {
      maps?: typeof google.maps;
    };
  }
  // Minimal global namespace for dynamic Google Maps script usage without @types/google.maps
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace google.maps {
    class LatLngBounds {
      extend(latLng: { lat: number; lng: number }): void;
    }
    class Map {
      constructor(el: HTMLElement, opts: Record<string, unknown>);
      addListener(eventName: string, handler: (...args: unknown[]) => void): { remove(): void };
      getBounds(): {
        getSouthWest(): { lat(): number; lng(): number };
        getNorthEast(): { lat(): number; lng(): number };
      } | null | undefined;
      panTo(latLng: { lat: number; lng: number }): void;
      setZoom(zoom: number): void;
      fitBounds(bounds: LatLngBounds, padding?: number): void;
      setOptions(opts: Record<string, unknown>): void;
    }
    class Marker {
      constructor(opts: Record<string, unknown>);
      setMap(map: Map | null): void;
      addListener(eventName: string, handler: () => void): void;
    }
  }
}

/**
 * Dynamically loads the Google Maps JS SDK only after explicit user privacy consent.
 * Never called on initial boot or when MapLibre is active.
 */
export function loadGoogleMaps(apiKey: string): Promise<typeof google.maps> {
  if (!apiKey.trim()) {
    return Promise.reject(new Error("Google Maps API key is not configured."));
  }

  if (window.google?.maps) {
    return Promise.resolve(window.google.maps);
  }

  if (!googleMapsLoadPromise) {
    googleMapsLoadPromise = new Promise<typeof google.maps>((resolve, reject) => {
      const script = document.createElement("script");
      script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}`;
      script.async = true;
      script.defer = true;

      script.onload = () => {
        if (window.google?.maps) {
          resolve(window.google.maps);
        } else {
          googleMapsLoadPromise = null;
          reject(new Error("Google Maps SDK loaded without initializing window.google.maps."));
        }
      };

      script.onerror = () => {
        googleMapsLoadPromise = null;
        reject(new Error("Failed to load Google Maps SDK."));
      };

      document.head.appendChild(script);
    });
  }

  return googleMapsLoadPromise;
}
