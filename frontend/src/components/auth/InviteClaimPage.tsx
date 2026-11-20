import { useMutation } from "@tanstack/react-query";
import { UserPlus } from "lucide-react";
import { useId, useState, type FormEvent } from "react";
import { Link, useLocation, useParams } from "wouter";
import { claimInvite } from "../../api/auth";
import { ApiError } from "../../api/client";
import { useAuth } from "../../context/AuthContext";

/**
 * Renders the friend invite claim form at `/join/:token`.
 *
 * Security invariant (ADR-0002): Never consume the invite on GET / initial render.
 * Consumption occurs strictly on explicit form submission.
 */
export function InviteClaimPage() {
  const { token } = useParams<{ token: string }>();
  const [, navigate] = useLocation();
  const { setAuthenticatedUser } = useAuth();

  const nameId = useId();
  const nameErrorId = useId();
  const emailId = useId();
  const emailErrorId = useId();

  const [displayName, setDisplayName] = useState("");
  const [email, setEmail] = useState("");

  const mutation = useMutation({
    mutationFn: (payload: { displayName: string; email: string }) => {
      if (!token) {
        throw new Error("Missing invite token.");
      }
      return claimInvite(token, payload);
    },
    onSuccess: (user) => {
      setAuthenticatedUser(user);
      navigate("/");
    },
  });

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    mutation.mutate({
      displayName: displayName.trim(),
      email: email.trim(),
    });
  };

  const apiError = mutation.error instanceof ApiError ? mutation.error : null;
  const nameError = apiError?.getFieldError("displayName");
  const emailError = apiError?.getFieldError("email");
  const generalError =
    apiError && !nameError && !emailError
      ? apiError.message
      : mutation.error && !apiError
        ? mutation.error.message
        : null;

  return (
    <main className="flex min-h-dvh items-center justify-center bg-[var(--surface-primary)] px-4 py-8 text-[var(--text-primary)]">
      <section className="w-full max-w-md rounded-2xl border border-black/10 bg-[var(--surface-primary)] p-6 shadow-sm dark:border-white/10">
        <div className="mb-4 flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--accent-brand)]/10 text-[var(--accent-brand)]">
            <UserPlus aria-hidden="true" className="h-5 w-5" />
          </div>
          <div>
            <h1 className="text-lg font-bold">You’re invited to yummy</h1>
            <p className="text-xs text-[var(--text-muted)]">Claim your one-time friend invite</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div>
            <label htmlFor={nameId} className="block text-sm font-medium">
              Display name
            </label>
            <input
              id={nameId}
              type="text"
              autoComplete="name"
              required
              minLength={2}
              maxLength={60}
              value={displayName}
              onChange={(e) => setDisplayName(e.target.value)}
              placeholder="Alex"
              aria-invalid={Boolean(nameError)}
              aria-describedby={nameError ? nameErrorId : undefined}
              className="mt-1.5 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 py-2.5 text-sm placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-brand)]/25 dark:border-white/15"
            />
            {nameError && (
              <p id={nameErrorId} role="alert" className="mt-1 text-xs text-[var(--accent-brand)]">
                {nameError}
              </p>
            )}
          </div>

          <div>
            <label htmlFor={emailId} className="block text-sm font-medium">
              Email address
            </label>
            <input
              id={emailId}
              type="email"
              autoComplete="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="alex@example.com"
              aria-invalid={Boolean(emailError)}
              aria-describedby={emailError ? emailErrorId : undefined}
              className="mt-1.5 min-h-11 w-full rounded-lg border border-black/15 bg-transparent px-3 py-2.5 text-sm placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-brand)]/25 dark:border-white/15"
            />
            {emailError && (
              <p id={emailErrorId} role="alert" className="mt-1 text-xs text-[var(--accent-brand)]">
                {emailError}
              </p>
            )}
            <p className="mt-1 text-xs text-[var(--text-muted)]">
              Used only for passwordless magic-link sign-in on future devices.
            </p>
          </div>

          {generalError && (
            <div
              role="alert"
              className="rounded-lg border border-[var(--accent-brand)]/30 bg-[var(--accent-brand)]/10 px-3 py-2.5 text-sm text-[var(--accent-brand)]"
            >
              {generalError}
            </div>
          )}

          <button
            type="submit"
            disabled={!token || mutation.isPending}
            className="min-h-11 w-full rounded-lg bg-[var(--accent-brand)] px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {mutation.isPending ? "Creating account..." : "Join yummy"}
          </button>
        </form>

        <div className="mt-4 text-center">
          <Link href="/" className="inline-flex min-h-11 items-center text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-primary)]">
            Back to home
          </Link>
        </div>
      </section>
    </main>
  );
}
