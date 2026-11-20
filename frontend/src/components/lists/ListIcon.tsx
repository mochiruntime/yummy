export const LIST_EMOJI_OPTIONS = [
  "🥐", "🥖", "☕", "🫖", "🍰", "🍦",
  "🍽️", "🍕", "🍜", "🍣", "🌮", "🍔",
  "🍝", "🥟", "🥗", "🥩", "🍷", "🍺",
  "🍹", "📍", "⭐", "❤️", "✈️", "🏖️",
] as const;

export function ListIcon({ name, className = "" }: { name?: string | null; className?: string }) {
  const emoji = name && name.trim().length > 0 ? name.trim() : "📍";
  return (
    <span aria-hidden="true" className={`inline-flex items-center justify-center leading-none ${className}`}>
      {emoji}
    </span>
  );
}
