import { useEffect, useRef, useState } from "react";
import type { BBox, PoiSummary } from "../../api/types";
import { loadGoogleMaps } from "../../map/googleMapsLoader";

export interface GoogleMapsCanvasProps {
  apiKey: string;
  pois: PoiSummary[];
  pinDropMode: boolean;
  flyToTarget: { lat: number; lng: number; zoom?: number } | null;
  fitBoundsPoints?: Array<{ lat: number; lng: number }> | null;
  fitBoundsKey?: string | null;
  onBoundsChange: (bbox: BBox) => void;
  onSelectPoi: (poi: PoiSummary) => void;
  onMapClick?: (coords: { lat: number; lng: number }) => void;
}

export function GoogleMapsCanvas({
  apiKey,
  pois,
  pinDropMode,
  flyToTarget,
  fitBoundsPoints,
  fitBoundsKey,
  onBoundsChange,
  onSelectPoi,
  onMapClick,
}: GoogleMapsCanvasProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<google.maps.Map | null>(null);
  const markersRef = useRef<google.maps.Marker[]>([]);
  const lastFitKeyRef = useRef<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const onBoundsRef = useRef(onBoundsChange);
  const onSelectRef = useRef(onSelectPoi);
  const onMapClickRef = useRef(onMapClick);
  const pinDropRef = useRef(pinDropMode);

  useEffect(() => {
    onBoundsRef.current = onBoundsChange;
    onSelectRef.current = onSelectPoi;
    onMapClickRef.current = onMapClick;
    pinDropRef.current = pinDropMode;
  });

  useEffect(() => {
    let cancelled = false;
    let idleListener: { remove(): void } | undefined;
    let clickListener: { remove(): void } | undefined;

    loadGoogleMaps(apiKey)
      .then((maps) => {
        if (cancelled || !containerRef.current) {
          return;
        }
        const map = new maps.Map(containerRef.current, {
          center: { lat: 48.8566, lng: 2.3522 },
          zoom: 12,
          disableDefaultUI: false,
        });
        mapRef.current = map;

        idleListener = map.addListener("idle", () => {
          const bounds = map.getBounds();
          if (!bounds) {
            return;
          }
          const sw = bounds.getSouthWest();
          const ne = bounds.getNorthEast();
          onBoundsRef.current({
            minLng: sw.lng(),
            minLat: sw.lat(),
            maxLng: ne.lng(),
            maxLat: ne.lat(),
          });
        });

        clickListener = map.addListener("click", (evt: unknown) => {
          if (!pinDropRef.current || !onMapClickRef.current) {
            return;
          }
          const latLng = (evt as { latLng?: { lat(): number; lng(): number } }).latLng;
          if (latLng) {
            onMapClickRef.current({ lat: latLng.lat(), lng: latLng.lng() });
          }
        });
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setLoadError(err instanceof Error ? err.message : "Failed to load Google Maps.");
        }
      });

    return () => {
      cancelled = true;
      idleListener?.remove();
      clickListener?.remove();
      for (const m of markersRef.current) {
        m.setMap(null);
      }
      markersRef.current = [];
      mapRef.current = null;
    };
  }, [apiKey]);

  useEffect(() => {
    const map = mapRef.current;
    if (!map || !flyToTarget) {
      return;
    }
    map.panTo({ lat: flyToTarget.lat, lng: flyToTarget.lng });
    if (flyToTarget.zoom) {
      map.setZoom(flyToTarget.zoom);
    }
  }, [flyToTarget]);

  useEffect(() => {
    const map = mapRef.current;
    const maps = window.google?.maps;
    if (!fitBoundsKey) {
      lastFitKeyRef.current = null;
      return;
    }
    if (!map || !maps || !fitBoundsPoints || fitBoundsPoints.length === 0) {
      return;
    }
    if (lastFitKeyRef.current === fitBoundsKey) {
      return;
    }
    lastFitKeyRef.current = fitBoundsKey;

    if (fitBoundsPoints.length === 1) {
      map.panTo({ lat: fitBoundsPoints[0].lat, lng: fitBoundsPoints[0].lng });
      map.setZoom(15);
      return;
    }

    const bounds = new maps.LatLngBounds();
    for (const pt of fitBoundsPoints) {
      bounds.extend({ lat: pt.lat, lng: pt.lng });
    }
    map.fitBounds(bounds, 64);
  }, [fitBoundsPoints, fitBoundsKey]);

  useEffect(() => {
    const map = mapRef.current;
    const maps = window.google?.maps;
    if (!map || !maps) {
      return;
    }

    for (const m of markersRef.current) {
      m.setMap(null);
    }
    markersRef.current = pois.map((poi) => {
      const marker = new maps.Marker({
        map,
        position: { lat: poi.latitude, lng: poi.longitude },
        title: poi.name,
      });
      marker.addListener("click", () => onSelectRef.current(poi));
      return marker;
    });
  }, [pois]);

  if (loadError) {
    return (
      <div role="alert" className="flex h-full w-full items-center justify-center px-4 text-sm text-[var(--accent-brand)]">
        {loadError}
      </div>
    );
  }

  return <div ref={containerRef} data-testid="google-maps-canvas" className="h-full w-full" />;
}
