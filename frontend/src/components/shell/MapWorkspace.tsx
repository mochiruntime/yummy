import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Filter, List, MapPin, Plus, X } from "lucide-react";
import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { useLocation, useSearch } from "wouter";
import { ApiError } from "../../api/client";
import { fetchLists, listKeys } from "../../api/lists";
import { fetchPois, poiKeys, searchGeocode } from "../../api/pois";
import type { BBox, GeocodeResult, PoiDetail, PoiList, PoiSummary } from "../../api/types";
import { useAuth } from "../../context/AuthContext";
import { useIsDesktop } from "../../hooks/useIsDesktop";
import { ListManagerModal } from "../lists/ListManagerModal";
import { ListsTabPanel } from "../lists/ListsTabPanel";
import { GoogleMapsConsentModal } from "../map/GoogleMapsConsentModal";
import { MapLibreCanvas } from "../map/MapLibreCanvas";
import { CreatePoiModal } from "../pois/CreatePoiModal";
import { EditPoiModal } from "../pois/EditPoiModal";
import { PoiDetailPanel } from "../pois/PoiDetailPanel";
import { PoiRevisionsModal } from "../pois/PoiRevisionsModal";
import { ErrorBoundary } from "../ui/ErrorBoundary";
import { BottomSheet, type SheetSnap } from "./BottomSheet";
import { SpotsTabPanel } from "./SpotsTabPanel";
import { TopNavBar } from "./TopNavBar";

const GoogleMapsCanvas = lazy(() =>
  import("../map/GoogleMapsCanvas").then((mod) => ({ default: mod.GoogleMapsCanvas })),
);

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function parseListIdsParam(searchString: string): string[] {
  const raw = new URLSearchParams(searchString).get("lists");
  if (!raw) {
    return [];
  }
  return raw
    .split(",")
    .map((s) => s.trim())
    .filter((id) => UUID_RE.test(id));
}

export interface MapWorkspaceProps {
  sharedListId?: string;
  selectedPoiRouteId?: string;
  onOpenSignIn: () => void;
}

export function MapWorkspace({ sharedListId, selectedPoiRouteId, onOpenSignIn }: MapWorkspaceProps) {
  const { config, user, isAdmin, signOut } = useAuth();
  const isDesktop = useIsDesktop();
  const searchString = useSearch();
  const [, navigate] = useLocation();

  const isSharedListRoute = Boolean(sharedListId);
  const isValidSharedListId = Boolean(sharedListId && UUID_RE.test(sharedListId));

  const selectedListIds = useMemo(
    () => (sharedListId ? (isValidSharedListId ? [sharedListId] : []) : parseListIdsParam(searchString)),
    [sharedListId, isValidSharedListId, searchString],
  );

  const [activeTab, setActiveTab] = useState<"spots" | "lists">("spots");
  const [bbox, setBbox] = useState<BBox | null>(null);
  const [searchText, setSearchText] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [selectedPoiId, setSelectedPoiId] = useState<string | null>(selectedPoiRouteId ?? null);
  const [prevRoutePoiId, setPrevRoutePoiId] = useState<string | undefined>(selectedPoiRouteId);
  const [flyToTarget, setFlyToTarget] = useState<{ lat: number; lng: number; zoom?: number } | null>(
    null,
  );
  const [sheetSnap, setSheetSnap] = useState<SheetSnap>(selectedPoiRouteId ? "half" : "peek");
  const [listManagerOpen, setListManagerOpen] = useState(false);
  const [editingList, setEditingList] = useState<PoiList | null>(null);
  const [createPoiOpen, setCreatePoiOpen] = useState(false);
  const [editingPoiDetail, setEditingPoiDetail] = useState<PoiDetail | null>(null);
  const [historyPoi, setHistoryPoi] = useState<{ id: string; name: string; version: number; canManage: boolean } | null>(
    null,
  );
  const [pinDropMode, setPinDropMode] = useState(false);
  const [droppedCoords, setDroppedCoords] = useState<{ lat: number; lng: number } | null>(null);

  if (selectedPoiRouteId !== prevRoutePoiId) {
    setPrevRoutePoiId(selectedPoiRouteId);
    setSelectedPoiId(selectedPoiRouteId ?? null);
    if (selectedPoiRouteId) {
      setActiveTab("spots");
      setSheetSnap("half");
    }
  }

  // Privacy-first renderer state: always defaults to MapLibre; consent lives in memory only.
  const [renderer, setRenderer] = useState<"maplibre" | "google">("maplibre");
  const [hasGoogleConsent, setHasGoogleConsent] = useState(false);
  const [consentModalOpen, setConsentModalOpen] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchText.trim());
    }, 300);
    return () => clearTimeout(timer);
  }, [searchText]);

  const setListIdsInUrl = useCallback(
    (nextIds: string[]) => {
      const params = new URLSearchParams(searchString);
      if (nextIds.length > 0) {
        params.set("lists", nextIds.join(","));
      } else {
        params.delete("lists");
      }
      const qs = params.toString();
      navigate(qs ? `/?${qs}` : "/", { replace: true });
    },
    [navigate, searchString],
  );

  const toggleListFilter = useCallback(
    (id: string) => {
      if (isSharedListRoute) {
        navigate(`/?lists=${encodeURIComponent(id)}`);
        return;
      }
      const next = selectedListIds.includes(id)
        ? selectedListIds.filter((item) => item !== id)
        : [...selectedListIds, id];
      setListIdsInUrl(next);
    },
    [isSharedListRoute, navigate, selectedListIds, setListIdsInUrl],
  );

  const listsQuery = useQuery({
    queryKey: listKeys.all,
    queryFn: ({ signal }) => fetchLists(signal),
  });

  const activeQueryBbox = isSharedListRoute ? null : bbox;
  const poisQuery = useQuery({
    queryKey: poiKeys.list(activeQueryBbox, selectedListIds),
    queryFn: ({ signal }) =>
      fetchPois({ bbox: activeQueryBbox, listIds: selectedListIds, signal }),
    enabled: !isSharedListRoute || isValidSharedListId,
    placeholderData: keepPreviousData,
  });

  const mapCenter = useMemo(() => {
    if (!bbox) {
      return null;
    }
    return {
      lat: Number(((bbox.minLat + bbox.maxLat) / 2).toFixed(2)),
      lng: Number(((bbox.minLng + bbox.maxLng) / 2).toFixed(2)),
    };
  }, [bbox]);

  const geocodeQuery = useQuery({
    queryKey: ["geocode", debouncedQuery, mapCenter?.lat ?? null, mapCenter?.lng ?? null],
    queryFn: ({ signal }) =>
      searchGeocode({
        q: debouncedQuery,
        lat: mapCenter?.lat,
        lng: mapCenter?.lng,
        signal,
      }),
    enabled: debouncedQuery.length >= 2,
    staleTime: 60_000,
    retry: false,
  });

  const lists = useMemo(() => listsQuery.data ?? [], [listsQuery.data]);
  const sharedList = useMemo(
    () => (sharedListId ? lists.find((l) => l.id === sharedListId) : undefined),
    [lists, sharedListId],
  );

  const pois = useMemo(
    () => (isSharedListRoute && !isValidSharedListId ? [] : (poisQuery.data ?? [])),
    [isSharedListRoute, isValidSharedListId, poisQuery.data],
  );

  const filteredPois = useMemo(() => {
    const q = searchText.trim().toLowerCase();
    if (!q) {
      return pois;
    }
    return pois.filter(
      (poi) =>
        poi.name.toLowerCase().includes(q) ||
        (poi.address && poi.address.toLowerCase().includes(q)),
    );
  }, [pois, searchText]);

  const fitBoundsPoints = useMemo(() => {
    if (!isSharedListRoute || !isValidSharedListId || !poisQuery.data) {
      return null;
    }
    return poisQuery.data.map((p) => ({ lat: p.latitude, lng: p.longitude }));
  }, [isSharedListRoute, isValidSharedListId, poisQuery.data]);

  const handleOpenAddPlace = () => {
    setPinDropMode(false);
    setDroppedCoords(null);
    setCreatePoiOpen(true);
  };

  const handleMapClick = useCallback(
    (coords: { lat: number; lng: number }) => {
      if (!pinDropMode) {
        return;
      }
      setDroppedCoords(coords);
      setPinDropMode(false);
      setCreatePoiOpen(true);
    },
    [pinDropMode],
  );

  const handleSelectPoi = useCallback((poi: PoiSummary) => {
    setSelectedPoiId(poi.id);
    setFlyToTarget({ lat: poi.latitude, lng: poi.longitude });
    setActiveTab("spots");
    setSheetSnap("half");
  }, []);

  const handleSelectGeocode = (result: GeocodeResult) => {
    setFlyToTarget({ lat: result.latitude, lng: result.longitude, zoom: 15 });
    setSearchText("");
    setDebouncedQuery("");
  };

  const handleRendererToggle = () => {
    if (renderer === "google") {
      setRenderer("maplibre");
      return;
    }
    if (hasGoogleConsent) {
      setRenderer("google");
      return;
    }
    setConsentModalOpen(true);
  };

  const activeFilterSummary = useMemo(() => {
    if (isSharedListRoute || selectedListIds.length === 0) {
      return null;
    }
    if (selectedListIds.length === 1) {
      return lists.find((l) => l.id === selectedListIds[0])?.name ?? "1 list";
    }
    return `${selectedListIds.length} lists`;
  }, [isSharedListRoute, selectedListIds, lists]);

  const currentTab = isSharedListRoute ? "spots" : activeTab;

  const tabBar = (
    <div role="tablist" aria-label="Browse sections" className="flex items-center gap-1">
      <button
        type="button"
        role="tab"
        aria-selected={currentTab === "spots"}
        onClick={() => setActiveTab("spots")}
        className={`inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg text-xs font-semibold transition-colors ${
          currentTab === "spots"
            ? "bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]"
            : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
        }`}
      >
        <MapPin aria-hidden="true" className="h-4 w-4" />
        Spots ({filteredPois.length})
      </button>

      <button
        type="button"
        role="tab"
        aria-selected={currentTab === "lists"}
        onClick={() => {
          if (isSharedListRoute) {
            navigate("/");
          }
          setActiveTab("lists");
        }}
        className={`inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg text-xs font-semibold transition-colors ${
          currentTab === "lists"
            ? "bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]"
            : "text-[var(--text-muted)] hover:text-[var(--text-primary)]"
        }`}
      >
        <List aria-hidden="true" className="h-4 w-4" />
        Lists ({lists.length})
      </button>
    </div>
  );

  const panelBody =
    currentTab === "spots" ? (
      selectedPoiId ? (
        <PoiDetailPanel
          poiId={selectedPoiId}
          lists={lists}
          currentUser={user}
          isAdmin={isAdmin}
          onBack={() => {
            setSelectedPoiId(null);
            if (selectedPoiRouteId) {
              navigate("/");
            }
          }}
          onEdit={(detail) => setEditingPoiDetail(detail)}
          onOpenHistory={(detail) =>
            setHistoryPoi({
              id: detail.id,
              name: detail.name,
              version: detail.version,
              canManage: Boolean(user && (isAdmin || detail.createdBy?.id === user.id)),
            })
          }
          onDeleted={() => {
            setSelectedPoiId(null);
            if (selectedPoiRouteId) {
              navigate("/");
            }
          }}
        />
      ) : (
        <SpotsTabPanel
          pois={filteredPois}
          isLoading={poisQuery.isPending}
          selectedPoiId={selectedPoiId}
          onSelectPoi={handleSelectPoi}
          isSharedListRoute={isSharedListRoute}
          isValidSharedListId={isValidSharedListId}
          sharedList={sharedList}
          isListsLoading={listsQuery.isPending}
          isAuthenticated={user !== null}
          pinDropMode={pinDropMode}
          droppedCoords={droppedCoords}
          onCancelPinDrop={() => {
            setPinDropMode(false);
            setDroppedCoords(null);
          }}
        />
      )
    ) : (
      <ListsTabPanel
        lists={lists}
        visiblePois={pois}
        selectedListIds={selectedListIds}
        currentUser={user}
        isAdmin={isAdmin}
        onToggleListFilter={toggleListFilter}
        onCreateList={() => {
          setEditingList(null);
          setListManagerOpen(true);
        }}
        onEditList={(list) => {
          setEditingList(list);
          setListManagerOpen(true);
        }}
      />
    );

  return (
    <>
      <TopNavBar
        user={user}
        isAdmin={isAdmin}
        searchText={searchText}
        onSearchTextChange={setSearchText}
        onClearSearch={() => {
          setSearchText("");
          setDebouncedQuery("");
        }}
        geocodeSuggestions={geocodeQuery.data ?? []}
        showGeocodeDropdown={debouncedQuery.length >= 2}
        geocodeRateLimited={
          geocodeQuery.error instanceof ApiError && geocodeQuery.error.status === 429
        }
        onSelectGeocode={handleSelectGeocode}
        canUseGoogleMaps={Boolean(config?.googleMapsEnabled && config.googleMapsApiKey)}
        renderer={renderer}
        onToggleRenderer={handleRendererToggle}
        onOpenSignIn={onOpenSignIn}
        onSignOut={() => void signOut()}
      />

      <div className="relative flex flex-1 overflow-hidden">
        {isDesktop && (
          <aside
            aria-label="Places sidebar"
            className="flex h-full w-[380px] shrink-0 flex-col border-r border-black/10 bg-[var(--surface-primary)] lg:w-[400px] dark:border-white/10"
          >
            <div className="flex items-center justify-between gap-2 border-b border-black/10 px-3 py-2 dark:border-white/10">
              <div className="flex-1">{tabBar}</div>
              {user && (
                <button
                  type="button"
                  onClick={handleOpenAddPlace}
                  className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg bg-[var(--accent-brand)] px-3 text-xs font-semibold text-white hover:opacity-90"
                >
                  <Plus aria-hidden="true" className="h-4 w-4" />
                  Add place
                </button>
              )}
            </div>
            <div className="flex-1 overflow-y-auto p-4">{panelBody}</div>
          </aside>
        )}

        <div className="relative flex-1">
          {activeFilterSummary && (
            <div className="pointer-events-none absolute left-3 top-3 z-20">
              <button
                type="button"
                onClick={() => setListIdsInUrl([])}
                aria-label={`Clear list filter: ${activeFilterSummary}`}
                className="pointer-events-auto inline-flex min-h-11 items-center gap-1.5 rounded-full bg-[var(--accent-brand)] px-3.5 text-xs font-semibold text-white shadow-md hover:opacity-90"
              >
                <Filter aria-hidden="true" className="h-3.5 w-3.5" />
                <span>{activeFilterSummary}</span>
                <X aria-hidden="true" className="h-3.5 w-3.5" />
              </button>
            </div>
          )}

          <ErrorBoundary
            fallback={
              <div className="flex h-full w-full items-center justify-center px-4 text-sm text-[var(--text-muted)]">
                Unable to initialize interactive map.
              </div>
            }
          >
            {renderer === "google" && hasGoogleConsent && config?.googleMapsApiKey ? (
              <Suspense
                fallback={
                  <div className="flex h-full w-full items-center justify-center text-sm text-[var(--text-muted)]">
                    Loading Google Maps…
                  </div>
                }
              >
                <GoogleMapsCanvas
                  apiKey={config.googleMapsApiKey}
                  pois={filteredPois}
                  pinDropMode={pinDropMode}
                  flyToTarget={flyToTarget}
                  fitBoundsPoints={fitBoundsPoints}
                  fitBoundsKey={sharedListId ?? null}
                  onBoundsChange={setBbox}
                  onSelectPoi={handleSelectPoi}
                  onMapClick={handleMapClick}
                />
              </Suspense>
            ) : (
              <MapLibreCanvas
                styleUrl={config?.defaultMapStyleUrl ?? "https://tiles.openfreemap.org/styles/liberty"}
                pmtilesUrl={config?.pmtilesUrl ?? null}
                pois={filteredPois}
                selectedPoiId={selectedPoiId}
                pinDropMode={pinDropMode}
                flyToTarget={flyToTarget}
                fitBoundsPoints={fitBoundsPoints}
                fitBoundsKey={sharedListId ?? null}
                onBoundsChange={setBbox}
                onSelectPoi={handleSelectPoi}
                onMapClick={handleMapClick}
              />
            )}
          </ErrorBoundary>

          {!isDesktop && user && (
            <button
              type="button"
              onClick={handleOpenAddPlace}
              aria-label="Add place"
              className="fixed right-4 bottom-28 z-20 inline-flex min-h-12 items-center gap-1.5 rounded-full bg-[var(--accent-brand)] px-4 text-sm font-semibold text-white shadow-lg hover:opacity-90"
              style={{ marginBottom: "env(safe-area-inset-bottom)" }}
            >
              <Plus aria-hidden="true" className="h-5 w-5" />
              <span>Add place</span>
            </button>
          )}
        </div>

        {!isDesktop && (
          <BottomSheet
            snap={sheetSnap}
            onSnapChange={setSheetSnap}
            headerSummary={
              currentTab === "spots"
                ? `${filteredPois.length} ${filteredPois.length === 1 ? "spot" : "spots"}`
                : `${lists.length} ${lists.length === 1 ? "list" : "lists"}`
            }
          >
            <div className="mb-3 border-b border-black/10 pb-2 dark:border-white/10">{tabBar}</div>
            {panelBody}
          </BottomSheet>
        )}

        {consentModalOpen && (
          <GoogleMapsConsentModal
            onCancel={() => setConsentModalOpen(false)}
            onConfirm={() => {
              setHasGoogleConsent(true);
              setRenderer("google");
              setConsentModalOpen(false);
            }}
          />
        )}

        {listManagerOpen && user && (
          <ListManagerModal
            lists={lists}
            currentUser={user}
            isAdmin={isAdmin}
            initialEditList={editingList}
            onListDeleted={(deletedId) => {
              if (selectedListIds.includes(deletedId)) {
                setListIdsInUrl(selectedListIds.filter((id) => id !== deletedId));
              }
            }}
            onClose={() => {
              setListManagerOpen(false);
              setEditingList(null);
            }}
          />
        )}

        {createPoiOpen && user && (
          <CreatePoiModal
            lists={lists}
            currentUser={user}
            isAdmin={isAdmin}
            initialCoords={droppedCoords}
            mapCenter={mapCenter}
            onPickOnMap={() => {
              setCreatePoiOpen(false);
              setSelectedPoiId(null);
              setActiveTab("spots");
              setSheetSnap("peek");
              setPinDropMode(true);
            }}
            onCreated={(detail) => {
              setCreatePoiOpen(false);
              setDroppedCoords(null);
              setSelectedPoiId(detail.id);
              setActiveTab("spots");
              setFlyToTarget({ lat: detail.latitude, lng: detail.longitude, zoom: 15 });
            }}
            onClose={() => {
              setCreatePoiOpen(false);
              setDroppedCoords(null);
            }}
          />
        )}

        {editingPoiDetail && (
          <EditPoiModal
            detail={editingPoiDetail}
            onUpdated={(updated) => {
              setEditingPoiDetail(null);
              setFlyToTarget({ lat: updated.latitude, lng: updated.longitude });
            }}
            onClose={() => setEditingPoiDetail(null)}
          />
        )}

        {historyPoi && (
          <PoiRevisionsModal
            poiId={historyPoi.id}
            poiName={historyPoi.name}
            currentVersion={historyPoi.version}
            canManage={historyPoi.canManage}
            onReverted={(reverted) => {
              setHistoryPoi(null);
              setFlyToTarget({ lat: reverted.latitude, lng: reverted.longitude });
            }}
            onClose={() => setHistoryPoi(null)}
          />
        )}
      </div>
    </>
  );
}
