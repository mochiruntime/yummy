import { apiFetch } from "./client";
import type { Photo } from "./types";

export interface UploadPhotoInput {
  file: File;
  caption?: string;
  reviewId?: string;
  reviewItemId?: string;
  isPublic?: boolean;
}

export function uploadPhoto(poiId: string, input: UploadPhotoInput): Promise<Photo> {
  const formData = new FormData();
  formData.append("file", input.file);
  if (input.caption && input.caption.trim()) {
    formData.append("caption", input.caption.trim());
  }
  if (input.reviewId) {
    formData.append("reviewId", input.reviewId);
  }
  if (input.reviewItemId) {
    formData.append("reviewItemId", input.reviewItemId);
  }
  if (input.isPublic) {
    formData.append("isPublic", "1");
  }

  return apiFetch<Photo>(`/api/pois/${encodeURIComponent(poiId)}/photos`, {
    method: "POST",
    body: formData,
  });
}

export function updatePhotoVisibility(photoId: string, isPublic: boolean): Promise<Photo> {
  return apiFetch<Photo>(`/api/photos/${encodeURIComponent(photoId)}/visibility`, {
    method: "PATCH",
    body: { isPublic },
  });
}

export function deletePhoto(photoId: string): Promise<void> {
  return apiFetch<void>(`/api/photos/${encodeURIComponent(photoId)}`, {
    method: "DELETE",
  });
}
