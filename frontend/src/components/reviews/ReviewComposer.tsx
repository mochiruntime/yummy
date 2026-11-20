import { Camera, Plus, Star, X } from "lucide-react";
import { useEffect, useMemo, useRef } from "react";
import type { DraftPhotoFile, ReviewDraftData, ReviewDraftItem } from "../../utils/draftStorage";

const COMMON_CURRENCIES = ["CHF", "EUR", "USD", "GBP", "JPY", "CAD", "AUD"] as const;

const VIBE_OPTIONS = [
  "🔥 Must-order",
  "💸 Worth it",
  "🕯️ Cozy vibe",
  "🤫 Hidden gem",
  "⚡ Quick bite",
] as const;

const RATING_LABELS: Record<number, string> = {
  1: "1 ★ · Regrets were made",
  2: "2 ★ · Meh",
  3: "3 ★ · Solid spot",
  4: "4 ★ · Really good!",
  5: "5 ★ · Life-changing",
};

const VERDICT_OPTIONS: Array<{ value: ReviewDraftItem["verdict"]; label: string }> = [
  { value: "must_get", label: "🤤 Must-get" },
  { value: "good", label: "👌 Good" },
  { value: "skip", label: "😐 Skip" },
];

export function createEmptyReviewDraft(): ReviewDraftData {
  return {
    rating: 0,
    priceLevel: null,
    currency: "CHF",
    vibes: [],
    body: "",
    items: [],
    photos: [],
  };
}

function getAllCurrencies(): string[] {
  const intlWithValues = Intl as typeof Intl & {
    supportedValuesOf?: (key: "currency") => string[];
  };
  const supported =
    typeof intlWithValues.supportedValuesOf === "function"
      ? intlWithValues.supportedValuesOf("currency")
      : [...COMMON_CURRENCIES];
  return supported.filter((code) => !COMMON_CURRENCIES.includes(code as (typeof COMMON_CURRENCIES)[number]));
}

function FileThumbnail({
  photo,
  onRemove,
}: {
  photo: DraftPhotoFile;
  onRemove: () => void;
}) {
  const imgRef = useRef<HTMLImageElement>(null);

  useEffect(() => {
    if (typeof URL.createObjectURL !== "function") {
      return;
    }
    const url = URL.createObjectURL(photo.file);
    const img = imgRef.current;
    if (img) {
      img.src = url;
    }
    return () => {
      if (img) {
        img.removeAttribute("src");
      }
      if (typeof URL.revokeObjectURL === "function") {
        URL.revokeObjectURL(url);
      }
    };
  }, [photo.file]);

  return (
    <div className="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg border border-black/10 dark:border-white/10">
      <img ref={imgRef} alt={photo.file.name} className="h-full w-full object-cover" />
      <button
        type="button"
        aria-label={`Remove ${photo.file.name}`}
        onClick={onRemove}
        className="absolute right-0.5 top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-black/75 text-white hover:bg-black"
      >
        <X aria-hidden="true" className="h-2.5 w-2.5" />
      </button>
    </div>
  );
}

export interface ReviewComposerProps {
  value: ReviewDraftData;
  onChange: (next: ReviewDraftData) => void;
  title?: string;
  subtitle?: string;
  allowSkip?: boolean;
}

/**
 * Reusable tap-a-star review composer supporting spend tiers, all ISO currencies,
 * vibe pills, ordered dish/drink breakdowns, and per-item or general photos.
 */
export function ReviewComposer({
  value,
  onChange,
  title = "Been here already?",
  subtitle = "Tap a star to add your review (optional)",
  allowSkip = true,
}: ReviewComposerProps) {
  const generalFileRef = useRef<HTMLInputElement>(null);
  const otherCurrencies = useMemo(() => getAllCurrencies(), []);

  const isExpanded = value.rating > 0;

  const toggleVibe = (vibe: string) => {
    if (!value.vibes.includes(vibe) && value.vibes.length >= 10) {
      return;
    }
    const next = value.vibes.includes(vibe)
      ? value.vibes.filter((v) => v !== vibe)
      : [...value.vibes, vibe];
    onChange({ ...value, vibes: next });
  };

  const addItem = () => {
    if (value.items.length >= 30) {
      return;
    }
    const nextItem: ReviewDraftItem = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
      name: "",
      verdict: "must_get",
      priceAmount: "",
      note: "",
      photos: [],
    };
    onChange({ ...value, items: [...value.items, nextItem] });
  };

  const updateItem = (itemId: string, patch: Partial<ReviewDraftItem>) => {
    onChange({
      ...value,
      items: value.items.map((item) => (item.id === itemId ? { ...item, ...patch } : item)),
    });
  };

  const removeItem = (itemId: string) => {
    onChange({
      ...value,
      items: value.items.filter((item) => item.id !== itemId),
    });
  };

  const appendPhotos = (files: FileList | null, targetItemId?: string) => {
    if (!files || files.length === 0) {
      return;
    }
    const added: DraftPhotoFile[] = Array.from(files).map((file) => ({
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
      file,
    }));
    if (targetItemId) {
      const target = value.items.find((i) => i.id === targetItemId);
      if (target) {
        updateItem(targetItemId, { photos: [...target.photos, ...added] });
      }
      return;
    }
    onChange({ ...value, photos: [...value.photos, ...added] });
  };

  return (
    <section
      aria-label="Review composer"
      className="space-y-3 rounded-xl border border-black/10 bg-amber-500/[0.04] p-3 dark:border-white/10"
    >
      <div className="flex items-center justify-between gap-2">
        <div>
          <p className="text-xs font-bold">{title}</p>
          <p className="text-[11px] text-[var(--text-muted)]">{subtitle}</p>
        </div>

        <div className="flex items-center gap-0.5" role="group" aria-label="Star rating">
          {[1, 2, 3, 4, 5].map((star) => {
            const active = star <= value.rating;
            return (
              <button
                key={star}
                type="button"
                aria-label={`Rate ${star} ${star === 1 ? "star" : "stars"}`}
                aria-pressed={active}
                onClick={() => onChange({ ...value, rating: star })}
                className={`p-1 transition-transform hover:scale-110 ${
                  active ? "text-amber-500" : "text-zinc-300 dark:text-zinc-600"
                }`}
              >
                <Star
                  aria-hidden="true"
                  className="h-5 w-5"
                  fill={active ? "currentColor" : "none"}
                />
              </button>
            );
          })}
        </div>
      </div>

      {isExpanded && (
        <div className="space-y-3 border-t border-black/10 pt-3 dark:border-white/10">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-[11px] font-bold text-amber-800 dark:text-amber-300">
              {RATING_LABELS[value.rating]}
            </span>

            <div className="flex items-center gap-1.5">
              <select
                aria-label="Currency"
                value={value.currency}
                onChange={(e) => onChange({ ...value, currency: e.target.value })}
                className="min-h-8 rounded-lg border border-black/15 bg-[var(--surface-primary)] px-1.5 text-[11px] font-semibold dark:border-white/15"
              >
                <optgroup label="Common">
                  {COMMON_CURRENCIES.map((code) => (
                    <option key={code} value={code}>
                      {code}
                    </option>
                  ))}
                </optgroup>
                <optgroup label="All currencies">
                  {otherCurrencies.map((code) => (
                    <option key={code} value={code}>
                      {code}
                    </option>
                  ))}
                </optgroup>
              </select>

              <div
                role="group"
                aria-label="Price level"
                className="inline-flex overflow-hidden rounded-lg border border-black/15 bg-[var(--surface-primary)] dark:border-white/15"
              >
                {[1, 2, 3, 4].map((lvl) => {
                  const active = value.priceLevel === lvl;
                  return (
                    <button
                      key={lvl}
                      type="button"
                      aria-pressed={active}
                      onClick={() =>
                        onChange({ ...value, priceLevel: active ? null : lvl })
                      }
                      className={`px-2 py-1 text-[11px] font-semibold transition-colors ${
                        lvl > 1 ? "border-l border-black/10 dark:border-white/10" : ""
                      } ${
                        active
                          ? "bg-[var(--accent-brand)]/15 text-[var(--accent-brand)]"
                          : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                      }`}
                    >
                      {"$".repeat(lvl)}
                    </button>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Vibe pills */}
          <div className="flex flex-wrap gap-1.5">
            {VIBE_OPTIONS.map((vibe) => {
              const active = value.vibes.includes(vibe);
              return (
                <button
                  key={vibe}
                  type="button"
                  aria-pressed={active}
                  onClick={() => toggleVibe(vibe)}
                  className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold transition-colors ${
                    active
                      ? "border-[var(--accent-brand)] bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]"
                      : "border-black/15 bg-[var(--surface-primary)] text-[var(--text-primary)] dark:border-white/15"
                  }`}
                >
                  {vibe}
                </button>
              );
            })}
          </div>

          <textarea
            rows={2}
            maxLength={10000}
            aria-label="Review notes"
            value={value.body}
            onChange={(e) => onChange({ ...value, body: e.target.value })}
            placeholder="Your take (optional) — one honest sentence helps friends!"
            className="w-full rounded-lg border border-black/15 bg-[var(--surface-primary)] p-2.5 text-sm dark:border-white/15"
          />

          {/* Ordered dishes / drinks */}
          <div className="space-y-2 border-t border-dashed border-black/15 pt-2.5 dark:border-white/15">
            {value.items.map((item) => (
              <div
                key={item.id}
                className="space-y-2 rounded-xl border border-black/10 bg-[var(--surface-primary)] p-2.5 dark:border-white/10"
              >
                <div className="flex items-center gap-1.5">
                  <input
                    type="text"
                    maxLength={255}
                    aria-label="Dish or drink name"
                    value={item.name}
                    onChange={(e) => updateItem(item.id, { name: e.target.value })}
                    placeholder="Dish or drink name…"
                    className="min-h-9 flex-1 rounded-lg border border-black/15 bg-transparent px-2.5 text-xs font-semibold dark:border-white/15"
                  />
                  <div className="flex items-center rounded-lg border border-black/15 px-2 dark:border-white/15">
                    <span className="mr-1 text-[11px] font-semibold text-[var(--text-muted)]">
                      {value.currency}
                    </span>
                    <input
                      type="text"
                      inputMode="decimal"
                      aria-label="Item price"
                      value={item.priceAmount}
                      onChange={(e) => updateItem(item.id, { priceAmount: e.target.value })}
                      placeholder="0.00"
                      className="min-h-9 w-14 bg-transparent text-right text-xs outline-none"
                    />
                  </div>
                  <button
                    type="button"
                    aria-label="Remove item"
                    onClick={() => removeItem(item.id)}
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 dark:hover:bg-white/10"
                  >
                    <X aria-hidden="true" className="h-3.5 w-3.5" />
                  </button>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  {VERDICT_OPTIONS.map((opt) => {
                    const active = item.verdict === opt.value;
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => updateItem(item.id, { verdict: opt.value })}
                        className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition-colors ${
                          active
                            ? opt.value === "skip"
                              ? "border-amber-600 bg-amber-500/15 text-amber-800 dark:text-amber-300"
                              : "border-emerald-600 bg-emerald-500/15 text-emerald-800 dark:text-emerald-300"
                            : "border-black/15 text-[var(--text-muted)] dark:border-white/15"
                        }`}
                      >
                        {opt.label}
                      </button>
                    );
                  })}
                </div>

                <input
                  type="text"
                  maxLength={1000}
                  aria-label="Item note"
                  value={item.note}
                  onChange={(e) => updateItem(item.id, { note: e.target.value })}
                  placeholder="Quick note (optional — e.g. crispy crust, ask for extra chili)…"
                  className="min-h-9 w-full rounded-lg border border-black/15 bg-transparent px-2.5 text-xs dark:border-white/15"
                />

                <div className="flex flex-wrap items-center gap-2">
                  {item.photos.map((photo) => (
                    <FileThumbnail
                      key={photo.id}
                      photo={photo}
                      onRemove={() =>
                        updateItem(item.id, {
                          photos: item.photos.filter((p) => p.id !== photo.id),
                        })
                      }
                    />
                  ))}
                  <label className="inline-flex h-12 w-12 cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-black/25 text-[10px] font-semibold text-[var(--text-muted)] hover:border-[var(--accent-brand)] hover:text-[var(--accent-brand)] dark:border-white/25">
                    <Camera aria-hidden="true" className="h-3.5 w-3.5" />
                    <span>Add</span>
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp,image/gif"
                      multiple
                      className="sr-only"
                      onChange={(e) => {
                        appendPhotos(e.target.files, item.id);
                        e.target.value = "";
                      }}
                    />
                  </label>
                </div>
              </div>
            ))}

            <button
              type="button"
              disabled={value.items.length >= 30}
              onClick={addItem}
              className="inline-flex min-h-9 w-full items-center justify-center gap-1 rounded-lg border border-dashed border-black/20 bg-[var(--surface-primary)] px-3 text-xs font-semibold hover:bg-black/5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/20 dark:hover:bg-white/5"
            >
              <Plus aria-hidden="true" className="h-3.5 w-3.5" />
              {value.items.length >= 30
                ? "Maximum 30 items reached"
                : "Add dish / item you ordered (optional)"}
            </button>
          </div>

          {/* General photos & Skip review */}
          {value.photos.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {value.photos.map((photo) => (
                <FileThumbnail
                  key={photo.id}
                  photo={photo}
                  onRemove={() =>
                    onChange({
                      ...value,
                      photos: value.photos.filter((p) => p.id !== photo.id),
                    })
                  }
                />
              ))}
            </div>
          )}

          <div className="flex items-center justify-between gap-2">
            <input
              ref={generalFileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
              multiple
              className="sr-only"
              onChange={(e) => {
                appendPhotos(e.target.files);
                e.target.value = "";
              }}
            />
            <button
              type="button"
              onClick={() => generalFileRef.current?.click()}
              className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-dashed border-black/25 bg-[var(--surface-primary)] px-3 text-xs font-semibold text-[var(--text-muted)] hover:border-[var(--accent-brand)] hover:text-[var(--accent-brand)] dark:border-white/25"
            >
              <Camera aria-hidden="true" className="h-3.5 w-3.5" />
              <span>Add photos</span>
            </button>

            {allowSkip && (
              <button
                type="button"
                onClick={() => onChange(createEmptyReviewDraft())}
                className="text-xs text-[var(--text-muted)] underline hover:text-[var(--text-primary)]"
              >
                Skip review
              </button>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
