import { ArrowLeft, Check, Copy } from "lucide-react";
import { useState } from "react";
import { Link } from "wouter";
import type { PoiList } from "../../api/types";
import { sanitizeHexColor } from "../../utils/color";
import { ListIcon } from "./ListIcon";

export interface SharedListBannerProps {
  list: PoiList | undefined;
  isLoading: boolean;
  spotCount: number;
  isAuthenticated: boolean;
}

/**
 * Header banner rendered in the Spots tab when viewing a shareable `/lists/:id` URL.
 */
export function SharedListBanner({
  list,
  isLoading,
  spotCount,
  isAuthenticated,
}: SharedListBannerProps) {
  const [copied, setCopied] = useState(false);
  const [copyError, setCopyError] = useState<string | null>(null);

  if (isLoading) {
    return (
      <div className="rounded-xl border border-black/10 p-3 text-xs text-[var(--text-muted)] dark:border-white/10">
        Loading list…
      </div>
    );
  }

  if (!list) {
    return (
      <div
        role="alert"
        className="space-y-2 rounded-xl border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 p-3 text-xs"
      >
        <p className="font-semibold text-[var(--accent-brand)]">List not found.</p>
        <Link href="/" className="inline-flex min-h-9 items-center gap-1 font-semibold underline">
          <ArrowLeft aria-hidden="true" className="h-3.5 w-3.5" />
          Back to all lists
        </Link>
      </div>
    );
  }

  const handleCopyLink = async () => {
    setCopyError(null);
    try {
      await navigator.clipboard.writeText(window.location.href);
      setCopied(true);
    } catch {
      setCopyError("Clipboard unavailable — copy the URL from your address bar.");
    }
  };

  return (
    <div className="space-y-2.5 rounded-xl border border-black/10 p-3.5 dark:border-white/15">
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2.5">
          <span
            aria-hidden="true"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white"
            style={{ backgroundColor: sanitizeHexColor(list.color) }}
          >
            <ListIcon name={list.icon} className="h-4 w-4" />
          </span>
          <div className="min-w-0">
            <h2 className="truncate text-sm font-bold">{list.name}</h2>
            <p className="text-xs text-[var(--text-muted)]">
              by {list.ownerName} · {spotCount} {spotCount === 1 ? "spot" : "spots"}
            </p>
          </div>
        </div>
      </div>

      {isAuthenticated && (
        <p className="text-[11px] text-[var(--text-muted)]">
          Guests opening this link will only see Public spots in this list.
        </p>
      )}

      {copyError && (
        <p role="alert" className="text-xs text-[var(--accent-brand)]">
          {copyError}
        </p>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => void handleCopyLink()}
          className="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-[var(--text-primary)] px-3 text-xs font-semibold text-[var(--surface-primary)]"
        >
          {copied ? (
            <>
              <Check aria-hidden="true" className="h-3.5 w-3.5" />
              Copied
            </>
          ) : (
            <>
              <Copy aria-hidden="true" className="h-3.5 w-3.5" />
              Copy Share Link
            </>
          )}
        </button>

        <Link
          href="/"
          className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/15 dark:hover:bg-white/5"
        >
          <ArrowLeft aria-hidden="true" className="h-3.5 w-3.5" />
          All spots
        </Link>
      </div>
    </div>
  );
}
