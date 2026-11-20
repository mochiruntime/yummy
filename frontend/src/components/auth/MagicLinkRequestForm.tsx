import { useMutation } from "@tanstack/react-query";
import { Mail } from "lucide-react";
import { useId, useState, type FormEvent } from "react";
import { requestMagicLink } from "../../api/auth";
import { ApiError } from "../../api/client";

export function MagicLinkRequestForm() {
  const emailId = useId();
  const errorId = useId();
  const [email, setEmail] = useState("");

  const mutation = useMutation({
    mutationFn: (targetEmail: string) => requestMagicLink(targetEmail),
  });

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const trimmed = email.trim();
    if (!trimmed) {
      return;
    }
    mutation.mutate(trimmed);
  };

  const errorMessage =
    mutation.error instanceof ApiError
      ? mutation.error.getFieldError("email") ?? mutation.error.message
      : mutation.error instanceof Error
        ? mutation.error.message
        : null;

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      <div>
        <label htmlFor={emailId} className="block text-sm font-medium text-[var(--text-primary)]">
          Email address
        </label>
        <div className="relative mt-1.5">
          <Mail
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
          />
          <input
            id={emailId}
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="you@example.com"
            aria-invalid={errorMessage !== null}
            aria-describedby={errorMessage ? errorId : undefined}
            className="min-h-11 w-full rounded-lg border border-black/15 bg-transparent py-2.5 pl-10 pr-3 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--accent-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-brand)]/25 dark:border-white/15"
          />
        </div>
        {errorMessage && (
          <p id={errorId} role="alert" className="mt-1.5 text-xs text-[var(--accent-brand)]">
            {errorMessage}
          </p>
        )}
      </div>

      <button
        type="submit"
        disabled={mutation.isPending || email.trim().length === 0}
        className="min-h-11 w-full rounded-lg bg-[var(--accent-brand)] px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {mutation.isPending ? "Sending link..." : "Email me a sign-in link"}
      </button>

      {mutation.isSuccess && (
        <p
          role="status"
          aria-live="polite"
          className="rounded-lg bg-emerald-500/10 px-3 py-2.5 text-xs font-medium text-[var(--vis-public)]"
        >
          {mutation.data.message}
        </p>
      )}
    </form>
  );
}
