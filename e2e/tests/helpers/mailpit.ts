import { expect, type APIRequestContext } from "@playwright/test";

const MAILPIT_URL = process.env.MAILPIT_URL ?? "http://mailpit:8025";

interface MailpitMessageSummary {
  ID: string;
  Created: string;
  To: Array<{ Address: string }>;
  Subject: string;
}

interface MailpitMessagesResponse {
  messages: MailpitMessageSummary[];
}

interface MailpitMessageDetail {
  ID: string;
  Text: string;
}

/**
 * Returns the set of existing Mailpit message IDs for a given recipient so
 * subsequent polling only matches newly delivered emails.
 */
export async function getExistingMessageIds(
  request: APIRequestContext,
  recipientEmail: string,
): Promise<Set<string>> {
  const res = await request.get(`${MAILPIT_URL}/api/v1/messages?limit=50`);
  expect(res.ok()).toBe(true);
  const data = (await res.json()) as MailpitMessagesResponse;
  const normalized = recipientEmail.toLowerCase();

  return new Set(
    data.messages
      .filter((m) => m.To.some((to) => to.Address.toLowerCase() === normalized))
      .map((m) => m.ID),
  );
}

/**
 * Polls Mailpit until a new email arrives for `recipientEmail` (excluding `knownIds`)
 * and extracts the `/magic-link/<token>` URL from its plain-text body.
 */
export async function waitForMagicLinkUrl(
  request: APIRequestContext,
  recipientEmail: string,
  knownIds: Set<string>,
): Promise<string> {
  const normalized = recipientEmail.toLowerCase();
  let matchedId: string | undefined;

  await expect
    .poll(
      async () => {
        const res = await request.get(`${MAILPIT_URL}/api/v1/messages?limit=50`);
        if (!res.ok()) {
          return undefined;
        }
        const data = (await res.json()) as MailpitMessagesResponse;
        const found = data.messages.find(
          (m) =>
            !knownIds.has(m.ID) &&
            m.To.some((to) => to.Address.toLowerCase() === normalized),
        );
        matchedId = found?.ID;
        return matchedId;
      },
      {
        message: `Timed out waiting for magic-link email to ${recipientEmail}`,
        timeout: 15_000,
        intervals: [250, 500, 1000],
      },
    )
    .toBeTruthy();

  const detailRes = await request.get(`${MAILPIT_URL}/api/v1/message/${matchedId}`);
  expect(detailRes.ok()).toBe(true);
  const detail = (await detailRes.json()) as MailpitMessageDetail;

  const match = detail.Text.match(/https?:\/\/[^\s]+\/magic-link\/[^\s]+/);
  if (!match) {
    throw new Error(`Magic-link URL not found in email body:\n${detail.Text}`);
  }

  // Normalize origin to baseURL so tests work regardless of APP_FRONTEND_URL port/host.
  const parsed = new URL(match[0]);
  return `${parsed.pathname}${parsed.search}`;
}
