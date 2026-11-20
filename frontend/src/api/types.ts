import type { components, operations } from "./schema";

export type AppConfig = components["schemas"]["AppConfig"];
export type User = components["schemas"]["User"];
export type InviteSummary = components["schemas"]["InviteSummary"];
export type CreatedInvite =
  operations["createInvite"]["responses"][201]["content"]["application/json"];

export type PoiList = components["schemas"]["PoiList"];
export type PoiListInput = components["schemas"]["PoiListInput"];
export type PoiSummary = components["schemas"]["PoiSummary"];
export type GeocodeResult = components["schemas"]["GeocodeResult"];

export interface BBox {
  minLng: number;
  minLat: number;
  maxLng: number;
  maxLat: number;
}

export interface ApiViolation {
  propertyPath: string;
  message: string;
}

export interface ApiErrorPayload {
  error: string;
  violations?: ApiViolation[];
}
