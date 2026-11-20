import type { components, operations } from "./schema";

export type AppConfig = components["schemas"]["AppConfig"];
export type User = components["schemas"]["User"];
export type InviteSummary = components["schemas"]["InviteSummary"];
export type CreatedInvite =
  operations["createInvite"]["responses"][201]["content"]["application/json"];

export type PoiList = components["schemas"]["PoiList"];
export type PoiListInput = components["schemas"]["PoiListInput"];
export type PoiSummary = components["schemas"]["PoiSummary"];
export type PoiDetail = components["schemas"]["PoiDetail"];
export type PoiCreateInput = components["schemas"]["PoiCreateInput"];
export type PoiUpdateInput = components["schemas"]["PoiUpdateInput"];
export type PoiRevision = components["schemas"]["PoiRevision"];
export type GeocodeResult = components["schemas"]["GeocodeResult"];
export type Review = components["schemas"]["Review"];
export type ReviewInput = components["schemas"]["ReviewInput"];
export type ReviewItem = components["schemas"]["ReviewItem"];
export type ReviewItemInput = components["schemas"]["ReviewItemInput"];
export type Photo = components["schemas"]["Photo"];

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
