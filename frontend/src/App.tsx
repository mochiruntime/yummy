import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LogIn, LogOut, MapPin, Shield, Users, X } from "lucide-react";
import { useEffect, useId, useRef, useState } from "react";
import { Link, Route, Switch } from "wouter";
import { AdminInvitesPage } from "./components/auth/AdminInvitesPage";
import { InviteClaimPage } from "./components/auth/InviteClaimPage";
import { MagicLinkRequestForm } from "./components/auth/MagicLinkRequestForm";
import { MagicLinkVerifyPage } from "./components/auth/MagicLinkVerifyPage";
import { AuthProvider, useAuth } from "./context/AuthContext";

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        refetchOnWindowFocus: false,
      },
    },
  });
}

function SignInModal({ onClose }: { onClose: () => void }) {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const previouslyFocused =
      document.activeElement instanceof HTMLElement ? document.activeElement : null;

    // Move focus into the dialog on open for keyboard & screen-reader accessibility.
    const firstInput =
      dialogRef.current?.querySelector<HTMLElement>("input") ??
      dialogRef.current?.querySelector<HTMLElement>("button");
    firstInput?.focus();

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        onClose();
      }
    };
    window.addEventListener("keydown", onKeyDown);

    return () => {
      window.removeEventListener("keydown", onKeyDown);
      previouslyFocused?.focus();
    };
  }, [onClose]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      onClick={(e) => {
        if (e.target === e.currentTarget) {
          onClose();
        }
      }}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-xs"
    >
      <div
        ref={dialogRef}
        className="relative w-full max-w-md rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-6 text-[var(--text-primary)] shadow-xl dark:border-white/10"
      >
        <div className="mb-4 flex items-center justify-between gap-2">
          <h2 id={titleId} className="text-lg font-bold">
            Sign in to yummy
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close sign in dialog"
            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-black/5 hover:text-[var(--text-primary)] dark:hover:bg-white/10"
          >
            <X aria-hidden="true" className="h-5 w-5" />
          </button>
        </div>
        <p className="mb-4 text-xs text-[var(--text-muted)]">
          Enter your email to receive a one-time magic sign-in link.
        </p>
        <MagicLinkRequestForm />
      </div>
    </div>
  );
}

function HomeShell() {
  const { config, user, isAdmin, isBooting, bootError, signOut } = useAuth();
  const [signInOpen, setSignInOpen] = useState(false);

  if (isBooting) {
    return (
      <main className="flex h-dvh w-screen items-center justify-center bg-[var(--surface-primary)] text-[var(--text-primary)]">
        <p className="text-sm text-[var(--text-muted)]">Loading yummy…</p>
      </main>
    );
  }

  if (bootError) {
    return (
      <main className="flex h-dvh w-screen flex-col items-center justify-center bg-[var(--surface-primary)] px-4 text-center text-[var(--text-primary)]">
        <h1 className="text-xl font-bold">yummy</h1>
        <p role="alert" className="mt-2 text-sm text-[var(--accent-brand)]">
          Unable to connect to the server. Please try again.
        </p>
      </main>
    );
  }

  if (config && !config.publicBrowsingEnabled && user === null) {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-[var(--surface-primary)] px-4 py-8 text-[var(--text-primary)]">
        <section className="w-full max-w-md rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-6 shadow-sm dark:border-white/10">
          <div className="mb-4 flex items-center gap-2.5">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]">
              <Shield aria-hidden="true" className="h-5 w-5" />
            </div>
            <div>
              <h1 className="text-lg font-bold">yummy</h1>
              <p className="text-xs text-[var(--text-muted)]">Private instance — sign in required</p>
            </div>
          </div>
          <MagicLinkRequestForm />
        </section>
      </main>
    );
  }

  return (
    <div className="flex h-dvh w-screen flex-col bg-[var(--surface-primary)] text-[var(--text-primary)]">
      <header className="flex items-center justify-between gap-3 border-b border-black/10 bg-[var(--surface-overlay)] px-4 py-2 backdrop-blur-md dark:border-white/10">
        <Link href="/" className="inline-flex min-h-11 items-center gap-2 font-bold tracking-tight">
          <MapPin aria-hidden="true" className="h-5 w-5 text-[var(--accent-brand)]" />
          <span>yummy</span>
        </Link>

        <nav aria-label="Account" className="flex items-center gap-2">
          {user ? (
            <>
              <span className="hidden text-xs font-medium text-[var(--text-muted)] sm:inline">
                {user.displayName}
              </span>
              {isAdmin && (
                <Link
                  href="/invites"
                  className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/10"
                >
                  <Users aria-hidden="true" className="h-4 w-4" />
                  Invites
                </Link>
              )}
              <button
                type="button"
                onClick={() => void signOut()}
                className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-semibold hover:bg-black/5 dark:border-white/10 dark:hover:bg-white/10"
              >
                <LogOut aria-hidden="true" className="h-4 w-4" />
                Sign out
              </button>
            </>
          ) : (
            <button
              type="button"
              onClick={() => setSignInOpen(true)}
              className="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-[var(--accent-brand)] px-3.5 text-xs font-semibold text-white hover:opacity-90"
            >
              <LogIn aria-hidden="true" className="h-4 w-4" />
              Sign in
            </button>
          )}
        </nav>
      </header>

      <main className="flex flex-1 flex-col items-center justify-center px-4 text-center">
        <h1 className="text-2xl font-bold tracking-tight">yummy</h1>
        <p className="mt-2 max-w-md text-sm text-[var(--text-muted)]">
          Yummy - open-source collaborative mapping tool to drop, share, and organize custom POIs together. Built for foodie friends and travel squads.
        </p>
      </main>

      {signInOpen && <SignInModal onClose={() => setSignInOpen(false)} />}
    </div>
  );
}

export function AppRoutes() {
  return (
    <Switch>
      <Route path="/magic-link/:token" component={MagicLinkVerifyPage} />
      <Route path="/join/:token" component={InviteClaimPage} />
      <Route path="/invites" component={AdminInvitesPage} />
      <Route path="/" component={HomeShell} />
      <Route>{() => <HomeShell />}</Route>
    </Switch>
  );
}

export function App() {
  const [queryClient] = useState(createAppQueryClient);

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </QueryClientProvider>
  );
}
