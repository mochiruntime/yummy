import { apiFetch } from "./client";
import type {
  BBox,
  GeocodeResult,
  PoiCreateInput,
  PoiDetail,
  PoiRevision,
  PoiSummary,
  PoiUpdateInput,
} from "./types";

export const poiKeys = {
  all: ["pois"] as const,
  list: (bbox: BBox | null, listIds: readonly string[]) =>
    [
      "pois",
      "list",
      bbox
        ? `${bbox.minLng.toFixed(4)},${bbox.minLat.toFixed(4)},${bbox.maxLng.toFixed(4)},${bbox.maxLat.toFixed(4)}`
        : "all",
      [...listIds].sort().join(","),
    ] as const,
  detail: (id: string) => ["pois", "detail", id] as const,
  revisions: (id: string) => ["pois", "revisions", id] as const,
};

export function fetchPois(params: {
  bbox?: BBox | null;
  listIds?: readonly string[];
  signal?: AbortSignal;
}): Promise<PoiSummary[]> {
  const search = new URLSearchParams();
  if (params.bbox) {
    const { minLng, minLat, maxLng, maxLat } = params.bbox;
    search.set("bbox", `${minLng},${minLat},${maxLng},${maxLat}`);
  }
  if (params.listIds && params.listIds.length > 0) {
    search.set("listIds", params.listIds.join(","));
  }
  const qs = search.toString();
  return apiFetch<PoiSummary[]>(qs ? `/api/pois?${qs}` : "/api/pois", {
    signal: params.signal,
  });
}

export function fetchPoiDetail(id: string, signal?: AbortSignal): Promise<PoiDetail> {
  return apiFetch<PoiDetail>(`/api/pois/${encodeURIComponent(id)}`, { signal });
}

export function createPoi(input: PoiCreateInput): Promise<PoiDetail> {
  return apiFetch<PoiDetail>("/api/pois", {
    method: "POST",
    body: input,
  });
}

export function updatePoi(id: string, input: PoiUpdateInput): Promise<PoiDetail> {
  return apiFetch<PoiDetail>(`/api/pois/${encodeURIComponent(id)}`, {
    method: "PATCH",
    body: input,
  });
}

export function updatePoiVisibility(id: string, isPublic: boolean): Promise<PoiSummary> {
  return apiFetch<PoiSummary>(`/api/pois/${encodeURIComponent(id)}/visibility`, {
    method: "PATCH",
    body: { isPublic },
  });
}

export function deletePoi(id: string): Promise<void> {
  return apiFetch<void>(`/api/pois/${encodeURIComponent(id)}`, {
    method: "DELETE",
  });
}

export function fetchPoiRevisions(id: string, signal?: AbortSignal): Promise<PoiRevision[]> {
  return apiFetch<PoiRevision[]>(`/api/pois/${encodeURIComponent(id)}/revisions`, {
    signal,
  });
}

export function revertPoiRevision(id: string, revisionNumber: number): Promise<PoiDetail> {
  return apiFetch<PoiDetail>(
    `/api/pois/${encodeURIComponent(id)}/revisions/${revisionNumber}/revert`,
    { method: "POST" },
  );
}

export function searchGeocode(params: {
  q: string;
  lat?: number;
  lng?: number;
  signal?: AbortSignal;
}): Promise<GeocodeResult[]> {
  const search = new URLSearchParams({ q: params.q });
  if (params.lat !== undefined && params.lng !== undefined) {
    search.set("lat", String(params.lat));
    search.set("lng", String(params.lng));
  }
  return apiFetch<GeocodeResult[]>(`/api/geocode?${search.toString()}`, {
    signal: params.signal,
  });
}
