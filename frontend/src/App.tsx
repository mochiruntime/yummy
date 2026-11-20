import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Shield } from "lucide-react";
import { useState } from "react";
import { Route, Switch } from "wouter";
import { AdminInvitesPage } from "./components/auth/AdminInvitesPage";
import { InviteClaimPage } from "./components/auth/InviteClaimPage";
import { MagicLinkRequestForm } from "./components/auth/MagicLinkRequestForm";
import { MagicLinkVerifyPage } from "./components/auth/MagicLinkVerifyPage";
import { MapWorkspace } from "./components/shell/MapWorkspace";
import { Modal } from "./components/ui/Modal";
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
  return (
    <Modal title="Sign in to yummy" closeLabel="Close sign in dialog" onClose={onClose}>
      <p className="mb-4 text-xs text-[var(--text-muted)]">
        Enter your email to receive a one-time magic sign-in link.
      </p>
      <MagicLinkRequestForm />
    </Modal>
  );
}

function HomeShell({ sharedListId }: { sharedListId?: string }) {
  const { config, user, isBooting, bootError } = useAuth();
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
      <MapWorkspace sharedListId={sharedListId} onOpenSignIn={() => setSignInOpen(true)} />

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
      <Route path="/lists/:id">{(params) => <HomeShell sharedListId={params.id} />}</Route>
      <Route path="/">{() => <HomeShell />}</Route>
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
