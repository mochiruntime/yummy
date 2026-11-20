import type { components, operations } from "./schema";

export type AppConfig = components["schemas"]["AppConfig"];
export type User = components["schemas"]["User"];
export type InviteSummary = components["schemas"]["InviteSummary"];
export type CreatedInvite =
  operations["createInvite"]["responses"][201]["content"]["application/json"];

export interface ApiViolation {
  propertyPath: string;
  message: string;
}

export interface ApiErrorPayload {
  error: string;
  violations?: ApiViolation[];
}
