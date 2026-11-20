import { useMutation } from "@tanstack/react-query";
import { CheckCircle2, KeyRound } from "lucide-react";
import { Link, useLocation, useParams } from "wouter";
import { verifyMagicLink } from "../../api/auth";
import { ApiError } from "../../api/client";
import { useAuth } from "../../context/AuthContext";

/**
 * Renders the magic-link confirmation screen at `/magic-link/:token`.
 *
 * Security invariant (ADR-0002): Never consume the token on GET / initial render,
 * because IM link-preview crawlers prefetch URLs. Consumption occurs only when the
 * user explicitly clicks the sign-in button.
 */
export function MagicLinkVerifyPage() {
  const { token: rawParam } = useParams<{ token: string }>();
  const token = rawParam ? decodeURIComponent(rawParam) : undefined;
  const [, navigate] = useLocation();
  const { setAuthenticatedUser } = useAuth();

  const mutation = useMutation({
    mutationFn: (rawToken: string) => verifyMagicLink(rawToken),
    onSuccess: (user) => {
      setAuthenticatedUser(user);
      navigate("/");
    },
  });

  const errorMessage =
    mutation.error instanceof ApiError
      ? mutation.error.message
      : mutation.error instanceof Error
        ? mutation.error.message
        : null;

  return (
    <main className="flex min-h-dvh items-center justify-center bg-[var(--surface-primary)] px-4 py-8 text-[var(--text-primary)]">
      <section className="w-full max-w-md rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-6 shadow-sm dark:border-white/10">
        <div className="mb-4 flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]">
            <KeyRound aria-hidden="true" className="h-5 w-5" />
          </div>
          <div>
            <h1 className="text-lg font-bold">Sign in to yummy</h1>
            <p className="text-xs text-[var(--text-muted)]">Confirm your one-time magic link</p>
          </div>
        </div>

        <p className="mb-5 text-sm text-[var(--text-muted)]">
          Click the button below to finish signing in on this device. This link can only be used once.
        </p>

        {errorMessage && (
          <div
            role="alert"
            className="mb-4 rounded-lg border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 px-3 py-2.5 text-sm text-[var(--accent-brand)]"
          >
            {errorMessage}
          </div>
        )}

        <button
          type="button"
          disabled={!token || mutation.isPending}
          onClick={() => token && mutation.mutate(token)}
          className="flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-[var(--accent-brand)] px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <CheckCircle2 aria-hidden="true" className="h-4 w-4" />
          {mutation.isPending ? "Signing in..." : "Sign in to yummy"}
        </button>

        <div className="mt-4 text-center">
          <Link href="/" className="inline-flex min-h-11 items-center text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-primary)]">
            Back to home
          </Link>
        </div>
      </section>
    </main>
  );
}
