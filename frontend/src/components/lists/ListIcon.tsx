import { Bookmark, Coffee, Croissant, MapPin, Utensils, Wine } from "lucide-react";

export const LIST_ICON_OPTIONS = [
  { id: "bookmark", label: "Bookmark" },
  { id: "utensils", label: "Restaurant" },
  { id: "coffee", label: "Café" },
  { id: "croissant", label: "Bakery" },
  { id: "wine", label: "Bar / Wine" },
  { id: "map-pin", label: "Spot" },
] as const;

export function ListIcon({ name, className = "h-3.5 w-3.5" }: { name?: string | null; className?: string }) {
  switch (name) {
    case "utensils":
      return <Utensils aria-hidden="true" className={className} />;
    case "coffee":
      return <Coffee aria-hidden="true" className={className} />;
    case "croissant":
      return <Croissant aria-hidden="true" className={className} />;
    case "wine":
      return <Wine aria-hidden="true" className={className} />;
    case "map-pin":
      return <MapPin aria-hidden="true" className={className} />;
    case "bookmark":
    default:
      return <Bookmark aria-hidden="true" className={className} />;
  }
}
