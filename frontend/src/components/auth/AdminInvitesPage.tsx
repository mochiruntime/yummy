import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Check, Copy, Link2, Plus, Trash2 } from "lucide-react";
import { useId, useState } from "react";
import { Link } from "wouter";
import { createInvite, listInvites, queryKeys, revokeInvite } from "../../api/auth";
import { ApiError } from "../../api/client";
import type { CreatedInvite, InviteSummary } from "../../api/types";
import { useAuth } from "../../context/AuthContext";

const STATUS_BADGE_CLASSES: Record<InviteSummary["status"], string> = {
  pending: "bg-[var(--vis-private)]/15 text-[var(--vis-private)]",
  claimed: "bg-[var(--vis-public)]/15 text-[var(--vis-public)]",
  revoked: "bg-black/10 text-[var(--text-muted)] dark:bg-white/10",
  expired: "bg-amber-500/15 text-amber-700 dark:text-amber-300",
};

export function AdminInvitesPage() {
  const { isAdmin, isBooting } = useAuth();
  const queryClient = useQueryClient();
  const expirySelectId = useId();
  const labelInputId = useId();
  const [expiresInHours, setExpiresInHours] = useState(168);
  const [label, setLabel] = useState("");
  const [latestInvite, setLatestInvite] = useState<CreatedInvite | null>(null);
  const [copied, setCopied] = useState(false);
  const [copyError, setCopyError] = useState<string | null>(null);

  const invitesQuery = useQuery({
    queryKey: queryKeys.invites,
    queryFn: ({ signal }) => listInvites(signal),
    enabled: isAdmin,
  });

  const createMutation = useMutation({
    mutationFn: (params: { hours: number; label: string }) =>
      createInvite(params.hours, params.label),
    onSuccess: (created) => {
      setLatestInvite(created);
      setLabel("");
      setCopied(false);
      void queryClient.invalidateQueries({ queryKey: queryKeys.invites });
    },
  });

  const revokeMutation = useMutation({
    mutationFn: (id: string) => revokeInvite(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.invites });
    },
  });

  const handleCopy = async () => {
    if (!latestInvite) {
      return;
    }
    setCopyError(null);
    try {
      await navigator.clipboard.writeText(latestInvite.inviteUrl);
      setCopied(true);
    } catch {
      setCopyError("Clipboard access unavailable — please copy the link manually.");
    }
  };

  if (isBooting) {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-[var(--surface-primary)] text-[var(--text-muted)]">
        <p className="text-sm">Loading…</p>
      </main>
    );
  }

  if (!isAdmin) {
    return (
      <main className="mx-auto max-w-2xl px-4 py-10 text-[var(--text-primary)]">
        <p role="alert" className="text-sm text-[var(--accent-brand)]">
          Admin access is required to manage friend invites.
        </p>
        <Link href="/" className="mt-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium underline">
          <ArrowLeft aria-hidden="true" className="h-4 w-4" />
          Back to map
        </Link>
      </main>
    );
  }

  const mutationError =
    createMutation.error instanceof ApiError
      ? createMutation.error.message
      : revokeMutation.error instanceof ApiError
        ? revokeMutation.error.message
        : null;

  return (
    <main className="min-h-dvh bg-[var(--surface-primary)] px-4 py-6 text-[var(--text-primary)]">
      <div className="mx-auto max-w-2xl space-y-6">
        <header className="flex items-center justify-between gap-4">
          <div>
            <Link
              href="/"
              className="inline-flex min-h-11 items-center gap-1.5 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-primary)]"
            >
              <ArrowLeft aria-hidden="true" className="h-4 w-4" />
              Back to map
            </Link>
            <h1 className="text-xl font-bold">Friend Invites</h1>
            <p className="text-xs text-[var(--text-muted)]">
              Generate one-time invite links to share with friends in Signal, WhatsApp, or Telegram.
            </p>
          </div>
        </header>

        <section className="rounded-2xl border border-black/10 p-4 dark:border-white/10">
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-48 flex-[2]">
              <label htmlFor={labelInputId} className="block text-xs font-medium text-[var(--text-muted)]">
                Label (optional)
              </label>
              <input
                id={labelInputId}
                type="text"
                maxLength={100}
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder="e.g. For Alex on Signal"
                className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-[var(--surface-primary)] px-3 text-sm placeholder:text-[var(--text-muted)] dark:border-white/15"
              />
            </div>

            <div className="min-w-36 flex-1">
              <label htmlFor={expirySelectId} className="block text-xs font-medium text-[var(--text-muted)]">
                Link expires in
              </label>
              <select
                id={expirySelectId}
                value={expiresInHours}
                onChange={(e) => setExpiresInHours(Number(e.target.value))}
                className="mt-1 min-h-11 w-full rounded-lg border border-black/15 bg-[var(--surface-primary)] px-3 text-sm dark:border-white/15"
              >
                <option value={24}>24 hours</option>
                <option value={72}>3 days</option>
                <option value={168}>7 days</option>
                <option value={720}>30 days</option>
              </select>
            </div>

            <button
              type="button"
              disabled={createMutation.isPending}
              onClick={() => createMutation.mutate({ hours: expiresInHours, label })}
              className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-[var(--accent-brand)] px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              <Plus aria-hidden="true" className="h-4 w-4" />
              {createMutation.isPending ? "Generating..." : "Generate Invite Link"}
            </button>
          </div>

          {latestInvite && (
            <div
              role="status"
              aria-live="polite"
              className="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3"
            >
              <div className="flex items-center gap-1.5 text-xs font-semibold text-[var(--vis-public)]">
                <Link2 aria-hidden="true" className="h-3.5 w-3.5" />
                One-time invite created (copy now — shown once)
              </div>
              <div className="mt-2 flex items-center gap-2">
                <input
                  type="text"
                  readOnly
                  aria-label="Generated invite URL"
                  value={latestInvite.inviteUrl}
                  className="min-h-11 flex-1 rounded-lg border border-black/10 bg-[var(--surface-primary)] px-3 text-xs font-mono dark:border-white/10"
                />
                <button
                  type="button"
                  onClick={handleCopy}
                  className="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-[var(--text-primary)] px-3 text-xs font-semibold text-[var(--surface-primary)]"
                >
                  {copied ? (
                    <>
                      <Check aria-hidden="true" className="h-4 w-4" />
                      Copied
                    </>
                  ) : (
                    <>
                      <Copy aria-hidden="true" className="h-4 w-4" />
                      Copy Link
                    </>
                  )}
                </button>
              </div>
            </div>
          )}

          {copyError && (
            <p role="alert" className="mt-2 text-xs text-[var(--accent-brand)]">
              {copyError}
            </p>
          )}

          {mutationError && (
            <p role="alert" className="mt-3 text-xs text-[var(--accent-brand)]">
              {mutationError}
            </p>
          )}
        </section>

        <section aria-label="Invite history" className="space-y-2">
          <h2 className="text-sm font-semibold text-[var(--text-muted)]">Issued Invites</h2>

          {invitesQuery.isPending && (
            <p className="text-sm text-[var(--text-muted)]">Loading invites...</p>
          )}

          {invitesQuery.isError && (
            <p role="alert" className="text-sm text-[var(--accent-brand)]">
              Failed to load invites.
            </p>
          )}

          {invitesQuery.data && invitesQuery.data.length === 0 && (
            <p className="rounded-xl border border-dashed border-black/15 p-4 text-center text-sm text-[var(--text-muted)] dark:border-white/15">
              No invites generated yet.
            </p>
          )}

          {invitesQuery.data && invitesQuery.data.length > 0 && (
            <ul className="divide-y divide-black/10 rounded-2xl border border-black/10 dark:divide-white/10 dark:border-white/10">
              {invitesQuery.data.map((invite) => (
                <li key={invite.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span
                        className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_BADGE_CLASSES[invite.status]}`}
                      >
                        {invite.status}
                      </span>
                      {invite.label ? (
                        <span className="truncate text-sm font-medium text-[var(--text-primary)]">
                          {invite.label}
                        </span>
                      ) : (
                        <span className="truncate text-xs font-mono text-[var(--text-muted)]">
                          {invite.id.slice(0, 8)}
                        </span>
                      )}
                    </div>
                    {invite.claimedBy ? (
                      <p className="mt-1 text-xs text-[var(--text-muted)]">
                        Claimed by <span className="font-medium text-[var(--text-primary)]">{invite.claimedBy.displayName}</span>{" "}
                        ({invite.claimedBy.email})
                        {invite.claimedAt ? ` on ${new Date(invite.claimedAt).toLocaleDateString()}` : ""}
                      </p>
                    ) : (
                      <p className="mt-1 text-xs text-[var(--text-muted)]">
                        Expires {new Date(invite.expiresAt).toLocaleDateString()}
                      </p>
                    )}
                  </div>

                  {invite.status === "pending" && (
                    <button
                      type="button"
                      disabled={revokeMutation.isPending}
                      onClick={() => revokeMutation.mutate(invite.id)}
                      aria-label={`Revoke invite ${invite.id.slice(0, 8)}`}
                      className="inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-lg border border-black/10 px-3 text-xs font-medium text-[var(--accent-brand)] hover:bg-[var(--accent-brand)]/10 dark:border-white/10"
                    >
                      <Trash2 aria-hidden="true" className="h-4 w-4" />
                      Revoke
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </main>
  );
}
