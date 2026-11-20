import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useCallback, useContext, useMemo, type ReactNode } from "react";
import { fetchConfig, fetchMe, logout, queryKeys } from "../api/auth";
import type { AppConfig, User } from "../api/types";

export interface AuthContextValue {
  config: AppConfig | undefined;
  user: User | null;
  isAdmin: boolean;
  isBooting: boolean;
  bootError: Error | null;
  setAuthenticatedUser: (user: User) => void;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();

  const configQuery = useQuery({
    queryKey: queryKeys.config,
    queryFn: ({ signal }) => fetchConfig(signal),
    staleTime: Infinity,
    retry: 1,
  });

  const meQuery = useQuery({
    queryKey: queryKeys.me,
    queryFn: ({ signal }) => fetchMe(signal),
    retry: false,
  });

  const setAuthenticatedUser = useCallback(
    (nextUser: User) => {
      queryClient.setQueryData(queryKeys.me, nextUser);
    },
    [queryClient],
  );

  const signOut = useCallback(async () => {
    const isUserScopedQuery = (queryKey: readonly unknown[]) =>
      queryKey[0] !== "config" && !(queryKey[0] === "auth" && queryKey[1] === "me");

    // Cancel in-flight user-scoped queries so they cannot resolve after logout and repopulate cache.
    await queryClient.cancelQueries({ predicate: (q) => isUserScopedQuery(q.queryKey) });

    try {
      await logout();
    } finally {
      // Wipe all user-scoped cached data from memory on logout while keeping public config.
      queryClient.setQueryData(queryKeys.me, null);
      queryClient.removeQueries({ predicate: (q) => isUserScopedQuery(q.queryKey) });
    }
  }, [queryClient]);

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
