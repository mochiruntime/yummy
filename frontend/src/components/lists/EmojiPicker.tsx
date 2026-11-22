import compactEmojis from "emojibase-data/en/compact.json";
import { Search } from "lucide-react";
import { useEffect, useId, useMemo, useRef, useState } from "react";

interface CompactEmojiEntry {
  unicode: string;
  label: string;
  group?: number;
  tags?: string[];
}

type CategoryId = "suggested" | "food" | "places" | "smileys" | "objects" | "flags";

interface EmojiCategory {
  id: CategoryId;
  tabIcon: string;
  label: string;
  groups?: number[];
}

const SUGGESTED_EMOJIS: readonly string[] = [
  "🥐", "🥖", "☕", "🍵", "🍕", "🍝", "🍜", "🍣",
  "🌮", "🍔", "🥟", "🥗", "🥩", "🍰", "🍦", "🍷",
  "🍺", "🍹", "✈️", "🏖️", "🏔️", "📍", "⭐", "❤️",
];

const CATEGORIES: readonly EmojiCategory[] = [
  { id: "suggested", tabIcon: "⭐", label: "Food & Travel (Suggested)" },
  { id: "food", tabIcon: "🍽️", label: "Food & Drink", groups: [4] },
  { id: "places", tabIcon: "🗺️", label: "Travel, Places & Nature", groups: [5, 3] },
  { id: "smileys", tabIcon: "😋", label: "Smileys & People", groups: [0, 1] },
  { id: "objects", tabIcon: "🎵", label: "Activities, Objects & Symbols", groups: [6, 7, 8] },
  { id: "flags", tabIcon: "🏳️", label: "Country Flags", groups: [9] },
];

const SEARCH_RESULT_LIMIT = 120;

const ALL_STANDARD_EMOJIS: CompactEmojiEntry[] = (compactEmojis as CompactEmojiEntry[]).filter(
  (entry) => entry.group !== undefined && entry.group !== 2,
);

const EMOJI_BY_UNICODE = new Map<string, CompactEmojiEntry>(
  ALL_STANDARD_EMOJIS.map((entry) => [entry.unicode, entry]),
);

export interface EmojiPickerProps {
  value: string;
  onChange: (emoji: string) => void;
}

/**
 * Privacy-first, offline, mobile-friendly emoji picker backed by bundled emojibase-data.
 * Covers all standard Unicode emojis across 6 compact icon tabs plus instant keyword search.
 */
export function EmojiPicker({ value, onChange }: EmojiPickerProps) {
  const searchId = useId();
  const gridRef = useRef<HTMLDivElement>(null);
  const [activeTab, setActiveTab] = useState<CategoryId>("suggested");
  const [searchQuery, setSearchQuery] = useState("");

  const trimmedQuery = searchQuery.trim().toLowerCase();

  useEffect(() => {
    if (gridRef.current) {
      gridRef.current.scrollTop = 0;
    }
  }, [activeTab, trimmedQuery]);

  const visibleEmojis = useMemo(() => {
    if (trimmedQuery.length > 0) {
      const matches: CompactEmojiEntry[] = [];
      for (const entry of ALL_STANDARD_EMOJIS) {
        const labelMatch = entry.label.toLowerCase().includes(trimmedQuery);
        const tagMatch = entry.tags?.some((tag) => tag.toLowerCase().includes(trimmedQuery)) ?? false;
        if (labelMatch || tagMatch) {
          matches.push(entry);
          if (matches.length >= SEARCH_RESULT_LIMIT) {
            break;
          }
        }
      }
      return matches;
    }

    if (activeTab === "suggested") {
      return SUGGESTED_EMOJIS.map(
        (unicode) => EMOJI_BY_UNICODE.get(unicode) ?? { unicode, label: unicode },
      );
    }

    const category = CATEGORIES.find((cat) => cat.id === activeTab);
    const groups = new Set(category?.groups ?? []);
    return ALL_STANDARD_EMOJIS.filter((entry) => entry.group !== undefined && groups.has(entry.group));
  }, [activeTab, trimmedQuery]);

  const activeCategory = CATEGORIES.find((cat) => cat.id === activeTab);
  const subheaderText =
    trimmedQuery.length > 0
      ? `Search results (${visibleEmojis.length}${visibleEmojis.length === SEARCH_RESULT_LIMIT ? "+" : ""})`
      : (activeCategory?.label ?? "");

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <label htmlFor={searchId} className="text-xs font-medium text-[var(--text-muted)]">
          Icon
        </label>
        <div className="relative flex-1 sm:max-w-56">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--text-muted)]"
          />
          <input
            id={searchId}
            type="search"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search emojis…"
            aria-label="Search emojis"
            className="min-h-9 w-full rounded-lg border border-black/15 bg-transparent pl-8 pr-2.5 text-xs focus:border-[var(--accent-brand)] focus:outline-none dark:border-white/15"
          />
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-black/10 dark:border-white/10">
        <div
          role="tablist"
          aria-label="Emoji categories"
          className="grid grid-cols-6 border-b border-black/10 dark:border-white/10"
        >
          {CATEGORIES.map((category) => {
            const isActive = trimmedQuery.length === 0 && activeTab === category.id;
            return (
              <button
                key={category.id}
                type="button"
                role="tab"
                aria-selected={isActive}
                aria-label={category.label}
                title={category.label}
                onClick={() => {
                  setActiveTab(category.id);
                  setSearchQuery("");
                }}
                className={`inline-flex min-h-10 items-center justify-center border-b-2 text-base transition-colors ${
                  isActive
                    ? "border-[var(--accent-brand)]"
                    : "border-transparent opacity-65 hover:opacity-100"
                }`}
              >
                {category.tabIcon}
              </button>
            );
          })}
        </div>

        <div className="space-y-1 p-1.5">
          <p className="px-1 pt-0.5 text-xs font-medium text-[var(--text-muted)]">{subheaderText}</p>

          <div
            ref={gridRef}
            role="tabpanel"
            aria-label={subheaderText}
            className="grid h-48 grid-cols-6 content-start gap-1 overflow-y-auto sm:grid-cols-8"
          >
            {visibleEmojis.length === 0 ? (
              <p className="col-span-full py-8 text-center text-xs text-[var(--text-muted)]">
                No matching emojis
              </p>
            ) : (
              visibleEmojis.map((entry) => {
                const isSelected = value === entry.unicode;
                return (
                  <button
                    key={entry.unicode}
                    type="button"
                    aria-label={`Select emoji ${entry.unicode}`}
                    aria-pressed={isSelected}
                    title={entry.label}
                    onClick={() => onChange(entry.unicode)}
                    className={`inline-flex min-h-11 items-center justify-center rounded-lg border text-lg transition-colors ${
                      isSelected
                        ? "border-[var(--accent-brand)] bg-[var(--accent-brand)]/10"
                        : "border-transparent hover:bg-black/5 dark:hover:bg-white/10"
                    }`}
                  >
                    {entry.unicode}
                  </button>
                );
              })
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
