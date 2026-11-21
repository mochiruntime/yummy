import type { ReactNode } from "react";

export interface ScopeOption<T extends string> {
  value: T;
  label: string;
  icon?: ReactNode;
}

export interface ScopeSegmentedControlProps<T extends string> {
  options: readonly ScopeOption<T>[];
  value: T;
  onChange: (next: T) => void;
  ariaLabel: string;
}

/**
 * Reusable 2- or 3-option segmented filter control shared across sidebar directory tabs.
 */
export function ScopeSegmentedControl<T extends string>({
  options,
  value,
  onChange,
  ariaLabel,
}: ScopeSegmentedControlProps<T>) {
  return (
    <div
      role="group"
      aria-label={ariaLabel}
      className="grid gap-1 rounded-xl bg-black/5 p-1 dark:bg-white/5"
      style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}
    >
      {options.map((option) => {
        const active = value === option.value;
        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(option.value)}
            className={`inline-flex min-h-11 items-center justify-center gap-1 rounded-lg px-2 text-xs font-semibold transition-colors ${
              active
                ? "bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm"
                : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
            }`}
          >
            {option.icon}
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
