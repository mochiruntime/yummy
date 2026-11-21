import {
  LngLatBounds,
  Map as MapLibreMap,
  Marker,
  NavigationControl,
  addProtocol,
  removeProtocol,
  setWorkerUrl,
  type MapMouseEvent,
} from "maplibre-gl";
import maplibreWorkerUrl from "maplibre-gl/dist/maplibre-gl-worker.mjs?url";
import "maplibre-gl/dist/maplibre-gl.css";

setWorkerUrl(maplibreWorkerUrl);
import { Protocol } from "pmtiles";
import { useEffect, useRef, useState } from "react";
import type { BBox, PoiSummary } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";

export interface MapCanvasProps {
  styleUrl: string;
  pmtilesUrl: string | null;
  pois: PoiSummary[];
  selectedPoiId: string | null;
  pinDropMode: boolean;
  flyToTarget: { lat: number; lng: number; zoom?: number } | null;
  fitBoundsPoints?: Array<{ lat: number; lng: number }> | null;
  fitBoundsKey?: string | null;
  onBoundsChange: (bbox: BBox) => void;
  onSelectPoi: (poi: PoiSummary) => void;
  onMapClick?: (coords: { lat: number; lng: number }) => void;
}

interface MarkerEntry {
  marker: Marker;
  color: string;
  selected: boolean;
  name: string;
}

function createMarkerButton(poi: PoiSummary, color: string, selected: boolean): HTMLButtonElement {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.setAttribute("aria-label", `Select ${poi.name}`);
  btn.className =
    "flex h-11 w-11 items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-brand)]";

  const dot = document.createElement("span");
  dot.className = "block rounded-full border-2 border-white shadow-md transition-transform";
  dot.style.backgroundColor = color;
  dot.style.width = selected ? "22px" : "16px";
  dot.style.height = selected ? "22px" : "16px";
  btn.appendChild(dot);

  return btn;
}

export function MapLibreCanvas({
  styleUrl,
  pmtilesUrl,
  pois,
  selectedPoiId,
  pinDropMode,
  flyToTarget,
  fitBoundsPoints,
  fitBoundsKey,
  onBoundsChange,
  onSelectPoi,
  onMapClick,
}: MapCanvasProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<MapLibreMap | null>(null);
  const markersRef = useRef<Map<string, MarkerEntry>>(new Map());
  const lastFitKeyRef = useRef<string | null>(null);
  const [mapError, setMapError] = useState<string | null>(null);

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
    const container = containerRef.current;
    if (!container) {
      return;
    }

    let protocolRegistered = false;
    if (pmtilesUrl) {
      // Registers pmtiles:// handler; defaultMapStyleUrl is expected to reference the pmtiles:// source.
      const protocol = new Protocol();
      addProtocol("pmtiles", protocol.tile);
      protocolRegistered = true;
    }

    let map: MapLibreMap;
    try {
      map = new MapLibreMap({
        container,
        style: styleUrl,
        center: [2.3522, 48.8566],
        zoom: 12,
      });
    } catch {
      if (protocolRegistered) {
        removeProtocol("pmtiles");
      }
      queueMicrotask(() => setMapError("Interactive map unavailable in this browser."));
      return;
    }

    map.addControl(new NavigationControl({ showCompass: false }), "top-right");
    mapRef.current = map;

    let debounceTimer: ReturnType<typeof setTimeout> | undefined;
    const emitBounds = () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const b = map.getBounds();
        onBoundsRef.current({
          minLng: b.getWest(),
          minLat: b.getSouth(),
          maxLng: b.getEast(),
          maxLat: b.getNorth(),
        });
      }, 250);
    };

    map.on("load", emitBounds);
    map.on("moveend", emitBounds);
    map.on("click", (e: MapMouseEvent) => {
      if (pinDropRef.current && onMapClickRef.current) {
        onMapClickRef.current({ lat: e.lngLat.lat, lng: e.lngLat.lng });
      }
    });

    const markers = markersRef.current;
    return () => {
      clearTimeout(debounceTimer);
      for (const entry of markers.values()) {
        entry.marker.remove();
      }
      markers.clear();
      map.remove();
      mapRef.current = null;
      if (protocolRegistered) {
        removeProtocol("pmtiles");
      }
    };
  }, [styleUrl, pmtilesUrl]);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) {
      return;
    }
    map.getCanvas().style.cursor = pinDropMode ? "crosshair" : "";
  }, [pinDropMode]);

  useEffect(() => {
    const map = mapRef.current;
    if (!map || !flyToTarget) {
      return;
    }
    map.flyTo({
      center: [flyToTarget.lng, flyToTarget.lat],
      zoom: flyToTarget.zoom ?? Math.max(map.getZoom(), 14),
    });
  }, [flyToTarget]);

  useEffect(() => {
    const map = mapRef.current;
    if (!fitBoundsKey) {
      lastFitKeyRef.current = null;
      return;
    }
    if (!map || !fitBoundsPoints || fitBoundsPoints.length === 0) {
      return;
    }
    if (lastFitKeyRef.current === fitBoundsKey) {
      return;
    }
    lastFitKeyRef.current = fitBoundsKey;

    if (fitBoundsPoints.length === 1) {
      map.flyTo({
        center: [fitBoundsPoints[0].lng, fitBoundsPoints[0].lat],
        zoom: 15,
      });
      return;
    }

    const bounds = new LngLatBounds();
    for (const pt of fitBoundsPoints) {
      bounds.extend([pt.lng, pt.lat]);
    }
    map.fitBounds(bounds, { padding: 64, maxZoom: 15 });
  }, [fitBoundsPoints, fitBoundsKey]);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) {
      return;
    }

    const activeIds = new Set(pois.map((p) => p.id));
    for (const [id, entry] of markersRef.current.entries()) {
      if (!activeIds.has(id)) {
        entry.marker.remove();
        markersRef.current.delete(id);
      }
    }

    for (const poi of pois) {
      const color = sanitizeHexColor(poi.lists[0]?.color);
      const selected = poi.id === selectedPoiId;
      const existing = markersRef.current.get(poi.id);

      if (
        existing &&
        existing.color === color &&
        existing.selected === selected &&
        existing.name === poi.name
      ) {
        existing.marker.setLngLat([poi.longitude, poi.latitude]);
        continue;
      }

      if (existing) {
        existing.marker.remove();
      }

      const btn = createMarkerButton(poi, color, selected);
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        onSelectRef.current(poi);
      });

      const marker = new Marker({ element: btn, anchor: "center" })
        .setLngLat([poi.longitude, poi.latitude])
        .addTo(map);

      markersRef.current.set(poi.id, {
        marker,
        color,
        selected,
        name: poi.name,
      });
    }
  }, [pois, selectedPoiId]);

  if (mapError) {
    return (
      <div className="flex h-full w-full items-center justify-center bg-black/5 px-4 text-center text-sm text-[var(--text-muted)] dark:bg-white/5">
        {mapError}
      </div>
    );
  }

  return <div ref={containerRef} data-testid="maplibre-canvas" className="h-full w-full" />;
}
