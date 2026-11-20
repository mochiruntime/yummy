import { useQuery, useQueryClient, type QueryClient } from "@tanstack/react-query";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { fetchConfig, fetchMe, logout, queryKeys } from "../api/auth";
import type { AppConfig, User } from "../api/types";
import { clearUserDrafts } from "../utils/draftStorage";

const AUTH_BROADCAST_CHANNEL = "yummy-auth";
export const SESSION_EXPIRED_EVENT = "yummy:session-expired";

type AuthBroadcastMessage =
  | { type: "signed-out"; senderId: string }
  | { type: "signed-in"; senderId: string };

function isUserScopedQuery(queryKey: readonly unknown[]): boolean {
  return queryKey[0] !== "config" && !(queryKey[0] === "auth" && queryKey[1] === "me");
}

/**
 * Cancels in-flight user-scoped queries, resets `me` to `null`, and purges cached user data (`NFR-PWA-02`).
 */
export async function clearUserSessionCache(queryClient: QueryClient): Promise<void> {
  queryClient.setQueryData(queryKeys.me, null);
  await queryClient.cancelQueries({ predicate: (q) => isUserScopedQuery(q.queryKey) });
  queryClient.setQueryData(queryKeys.me, null);
  queryClient.removeQueries({ predicate: (q) => isUserScopedQuery(q.queryKey) });
  await clearUserDrafts();
}

export interface AuthContextValue {
  config: AppConfig | undefined;
  user: User | null;
  isAdmin: boolean;
  isBooting: boolean;
  bootError: Error | null;
  sessionExpired: boolean;
  clearSessionExpired: () => void;
  setAuthenticatedUser: (user: User) => void;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const senderId = useId();
  const [sessionExpired, setSessionExpired] = useState(false);
  const meMutationSeqRef = useRef(0);
  const channelRef = useRef<BroadcastChannel | null>(null);

  const postAuthBroadcast = useCallback(
    (type: AuthBroadcastMessage["type"]) => {
      channelRef.current?.postMessage({ type, senderId } satisfies AuthBroadcastMessage);
    },
    [senderId],
  );

  const configQuery = useQuery({
    queryKey: queryKeys.config,
    queryFn: ({ signal }) => fetchConfig(signal),
    staleTime: Infinity,
    retry: 1,
  });

  const meQuery = useQuery({
    queryKey: queryKeys.me,
    queryFn: async ({ signal }) => {
      const seq = meMutationSeqRef.current;
      const hadUser = Boolean(queryClient.getQueryData<User | null>(queryKeys.me));
      const next = await fetchMe(signal);
      // Do not overwrite a login/logout that occurred while this fetch was in flight.
      if (meMutationSeqRef.current !== seq) {
        return queryClient.getQueryData<User | null>(queryKeys.me) ?? next;
      }
      if (hadUser && next === null) {
        void clearUserSessionCache(queryClient);
        window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT));
      }
      return next;
    },
    retry: false,
    staleTime: 60_000,
    refetchOnWindowFocus: true,
  });

  useEffect(() => {
    const handleSessionExpired = () => {
      setSessionExpired(true);
      postAuthBroadcast("signed-out");
    };
    window.addEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    return () => {
      window.removeEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    };
  }, [postAuthBroadcast]);

  useEffect(() => {
    if (typeof BroadcastChannel === "undefined") {
      return;
    }
    const channel = new BroadcastChannel(AUTH_BROADCAST_CHANNEL);
    channelRef.current = channel;
    channel.onmessage = (event: MessageEvent<AuthBroadcastMessage>) => {
      if (event.data?.senderId === senderId) {
        return;
      }
      if (event.data?.type === "signed-out") {
        setSessionExpired(false);
        void clearUserSessionCache(queryClient);
      } else if (event.data?.type === "signed-in") {
        setSessionExpired(false);
        void queryClient.invalidateQueries({ queryKey: queryKeys.me });
      }
    };
    return () => {
      channelRef.current = null;
      channel.close();
    };
  }, [queryClient, senderId]);

  const clearSessionExpired = useCallback(() => {
    setSessionExpired(false);
  }, []);

  const setAuthenticatedUser = useCallback(
    (nextUser: User) => {
      meMutationSeqRef.current += 1;
      setSessionExpired(false);
      queryClient.setQueryData(queryKeys.me, nextUser);
      postAuthBroadcast("signed-in");
    },
    [queryClient, postAuthBroadcast],
  );

  const signOut = useCallback(async () => {
    meMutationSeqRef.current += 1;
    setSessionExpired(false);
    await clearUserSessionCache(queryClient);

    try {
      await logout();
    } finally {
      postAuthBroadcast("signed-out");
    }
  }, [queryClient, postAuthBroadcast]);

  const value = useMemo<AuthContextValue>(() => {
    const user = meQuery.data ?? null;
    const isAdmin = user?.roles.includes("ROLE_ADMIN") ?? false;
    const isBooting = configQuery.isPending || meQuery.isPending;
    const bootError = (configQuery.error ?? meQuery.error ?? null) as Error | null;

    return {
      config: configQuery.data,
      user,
      isAdmin,
      isBooting,
      bootError,
      sessionExpired,
      clearSessionExpired,
      setAuthenticatedUser,
      signOut,
    };
  }, [
    configQuery.data,
    configQuery.error,
    configQuery.isPending,
    meQuery.data,
    meQuery.error,
    meQuery.isPending,
    sessionExpired,
    clearSessionExpired,
    setAuthenticatedUser,
    signOut,
  ]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within an AuthProvider.");
  }
  return ctx;
}
