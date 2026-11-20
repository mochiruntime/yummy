import type { ReactNode } from "react";

export type SheetSnap = "peek" | "half" | "full";

const SNAP_HEIGHT_CLASSES: Record<SheetSnap, string> = {
  peek: "h-24",
  half: "h-[48dvh]",
  full: "h-[86dvh]",
};

export interface BottomSheetProps {
  snap: SheetSnap;
  onSnapChange: (next: SheetSnap) => void;
  headerSummary: string;
  children: ReactNode;
}

function nextSnap(current: SheetSnap): SheetSnap {
  if (current === "peek") return "half";
  if (current === "half") return "full";
  return "peek";
}

/**
 * Mobile (< 768px) snap-point bottom sheet with >= 44px handle and safe-area padding.
 */
export function BottomSheet({ snap, onSnapChange, headerSummary, children }: BottomSheetProps) {
  return (
    <section
      aria-label="Places sheet"
      data-snap={snap}
      className={`fixed inset-x-0 bottom-0 z-30 flex flex-col rounded-t-2xl border-t border-black/10 bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-2xl transition-[height] duration-200 md:hidden dark:border-white/10 ${SNAP_HEIGHT_CLASSES[snap]}`}
      style={{ paddingBottom: "env(safe-area-inset-bottom)" }}
    >
      <button
        type="button"
        onClick={() => onSnapChange(nextSnap(snap))}
        aria-expanded={snap !== "peek"}
        aria-label={`Cycle sheet height (currently ${snap})`}
        className="flex min-h-11 w-full shrink-0 flex-col items-center justify-center gap-1.5 px-4 pt-2 pb-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-brand)]"
      >
        <span aria-hidden="true" className="h-1 w-10 rounded-full bg-black/20 dark:bg-white/25" />
        <span className="text-xs font-semibold text-[var(--text-muted)]">{headerSummary}</span>
      </button>

      <div className="flex-1 overflow-y-auto px-4 pb-4">{children}</div>
    </section>
  );
}
