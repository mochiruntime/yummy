import { ShieldAlert } from "lucide-react";
import { Modal } from "../ui/Modal";

export interface GoogleMapsConsentModalProps {
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Explicit privacy consent modal shown before any Google Maps script is loaded (FR-MAP-03).
 */
export function GoogleMapsConsentModal({ onConfirm, onCancel }: GoogleMapsConsentModalProps) {
  return (
    <Modal title="Enable Google Maps?" closeLabel="Cancel Google Maps" onClose={onCancel}>
      <div className="space-y-4">
        <div className="flex items-start gap-3 rounded-xl bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200">
          <ShieldAlert aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0" />
          <p>
            Switching from MapLibre to Google Maps loads scripts from Google servers and shares your
            IP address and map viewport with Google.
          </p>
        </div>

        <p className="text-xs text-[var(--text-muted)]">
          This choice is kept only for your current tab session. Reloading the page always resets
          back to privacy-first MapLibre.
        </p>

        <div className="flex items-center justify-end gap-2 pt-2">
          <button
            type="button"
            onClick={onCancel}
            className="min-h-11 rounded-lg border border-black/15 px-4 text-sm font-medium hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/10"
          >
            Stay on MapLibre
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="min-h-11 rounded-lg bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white hover:opacity-90"
          >
            Enable Google Maps
          </button>
        </div>
      </div>
    </Modal>
  );
}
