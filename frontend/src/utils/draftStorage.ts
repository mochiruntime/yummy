const DB_NAME = "yummy-drafts";
const STORE_NAME = "drafts";
const DB_VERSION = 1;

export interface DraftPhotoFile {
  id: string;
  file: File;
}

export interface ReviewDraftItem {
  id: string;
  name: string;
  verdict: "must_get" | "good" | "skip";
  priceAmount: string;
  note: string;
  photos: DraftPhotoFile[];
}

export interface ReviewDraftData {
  rating: number;
  priceLevel: number | null;
  currency: string;
  vibes: string[];
  body: string;
  items: ReviewDraftItem[];
  photos: DraftPhotoFile[];
}

export interface CreatePoiDraftData {
  name: string;
  address: string;
  description: string;
  latitude: string;
  longitude: string;
  selectedListIds: string[];
  isPublic: boolean;
  review: ReviewDraftData;
  updatedAt: number;
}

function openDraftDb(): Promise<IDBDatabase | null> {
  if (typeof indexedDB === "undefined") {
    return Promise.resolve(null);
  }

  return new Promise((resolve) => {
    try {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME);
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
}

export async function saveDraft<T>(key: string, value: T): Promise<void> {
  const db = await openDraftDb();
  if (!db) {
    return;
  }
  await new Promise<void>((resolve) => {
    try {
      const tx = db.transaction(STORE_NAME, "readwrite");
      tx.objectStore(STORE_NAME).put(value, key);
      tx.oncomplete = () => {
        db.close();
        resolve();
      };
      tx.onerror = () => {
        db.close();
        resolve();
      };
    } catch {
      db.close();
      resolve();
    }
  });
}

export async function loadDraft<T>(key: string): Promise<T | null> {
  const db = await openDraftDb();
  if (!db) {
    return null;
  }
  return new Promise<T | null>((resolve) => {
    try {
      const tx = db.transaction(STORE_NAME, "readonly");
      const req = tx.objectStore(STORE_NAME).get(key);
      req.onsuccess = () => {
        db.close();
        resolve((req.result as T | undefined) ?? null);
      };
      req.onerror = () => {
        db.close();
        resolve(null);
      };
    } catch {
      db.close();
      resolve(null);
    }
  });
}

export async function deleteDraft(key: string): Promise<void> {
  const db = await openDraftDb();
  if (!db) {
    return;
  }
  await new Promise<void>((resolve) => {
    try {
      const tx = db.transaction(STORE_NAME, "readwrite");
      tx.objectStore(STORE_NAME).delete(key);
      tx.oncomplete = () => {
        db.close();
        resolve();
      };
      tx.onerror = () => {
        db.close();
        resolve();
      };
    } catch {
      db.close();
      resolve();
    }
  });
}

/**
 * Purges all locally persisted drafts (called on sign-out / 401 session expiry per NFR-PWA-02).
 */
export async function clearUserDrafts(): Promise<void> {
  const db = await openDraftDb();
  if (!db) {
    return;
  }
  await new Promise<void>((resolve) => {
    try {
      const tx = db.transaction(STORE_NAME, "readwrite");
      tx.objectStore(STORE_NAME).clear();
      tx.oncomplete = () => {
        db.close();
        resolve();
      };
      tx.onerror = () => {
        db.close();
        resolve();
      };
    } catch {
      db.close();
      resolve();
    }
  });
}
