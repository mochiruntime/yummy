import { apiFetch } from "./client";
import type { PoiList, PoiListInput } from "./types";

export const listKeys = {
  all: ["lists"] as const,
};

export function fetchLists(signal?: AbortSignal): Promise<PoiList[]> {
  return apiFetch<PoiList[]>("/api/lists", { signal });
}

export function createList(input: PoiListInput): Promise<PoiList> {
  return apiFetch<PoiList>("/api/lists", {
    method: "POST",
    body: input,
  });
}

export function updateList(id: string, input: PoiListInput): Promise<PoiList> {
  return apiFetch<PoiList>(`/api/lists/${encodeURIComponent(id)}`, {
    method: "PATCH",
    body: input,
  });
}

export function deleteList(id: string): Promise<void> {
  return apiFetch<void>(`/api/lists/${encodeURIComponent(id)}`, {
    method: "DELETE",
  });
}
