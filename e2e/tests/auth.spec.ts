import { expect, test } from "@playwright/test";
import { getExistingMessageIds, waitForMagicLinkUrl } from "./helpers/mailpit";

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? "admin@example.com";

test.describe("Journey 1: Admin Magic Link & Friend Invite Flow", () => {
  test("admin signs in via Mailpit magic link, issues one-time invite, friend claims, logs out, and logs back in", async ({
    page,
    browser,
    request,
  }, testInfo) => {
    const uniqueSuffix = `${Date.now()}-${testInfo.project.name}-${testInfo.parallelIndex}`;
    const friendName = `Friend ${testInfo.parallelIndex}`;
    const friendEmail = `friend-${uniqueSuffix}@example.com`;

    // 1. Admin requests magic link
    const knownAdminMailIds = await getExistingMessageIds(request, ADMIN_EMAIL);
    await page.goto("/");
    await page.getByRole("button", { name: /^sign in$/i }).click();

    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await dialog.getByLabel(/email address/i).fill(ADMIN_EMAIL);
    await dialog.getByRole("button", { name: /email me a sign-in link/i }).click();
    await expect(
      dialog.getByText(/if an account exists, a sign-in link has been sent/i),
    ).toBeVisible();

    // 2. Fetch magic-link URL from Mailpit and verify non-consuming GET
    const adminMagicPath = await waitForMagicLinkUrl(request, ADMIN_EMAIL, knownAdminMailIds);
    await page.goto(adminMagicPath);

    const confirmAdminBtn = page.getByRole("button", { name: /sign in to yummy/i });
    await expect(confirmAdminBtn).toBeVisible();
    await expect(page.getByRole("button", { name: /sign out/i })).toHaveCount(0);

    // Explicit POST consumes token and establishes persistent session
    await confirmAdminBtn.click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("button", { name: /sign out/i })).toBeVisible();
    await expect(page.getByRole("link", { name: /invites/i })).toBeVisible();

    await page.reload();
    await expect(page.getByRole("button", { name: /sign out/i })).toBeVisible();

    // 3. Admin generates one-time invite link
    await page.getByRole("link", { name: /invites/i }).click();
    await expect(page).toHaveURL(/\/invites$/);
    await page.getByRole("button", { name: /generate invite link/i }).click();

    const inviteUrlInput = page.getByLabel(/generated invite url/i);
    await expect(inviteUrlInput).toBeVisible();
    const rawInviteUrl = await inviteUrlInput.inputValue();
    const invitePath = new URL(rawInviteUrl).pathname;

    // 4. Friend opens one-time invite in an isolated browser context
    const friendContext = await browser.newContext();
    const friendPage = await friendContext.newPage();
    try {
      await friendPage.goto(invitePath);
      await expect(friendPage.getByRole("heading", { name: /you’re invited to yummy/i })).toBeVisible();

      await friendPage.getByLabel(/display name/i).fill(friendName);
      await friendPage.getByLabel(/email address/i).fill(friendEmail);
      await friendPage.getByRole("button", { name: /join yummy/i }).click();

      await expect(friendPage).toHaveURL(/\/$/);
      await expect(friendPage.getByRole("button", { name: /sign out/i })).toBeVisible();
      // Regular members must not see Admin Invites link
      await expect(friendPage.getByRole("link", { name: /invites/i })).toHaveCount(0);

      // Session persists across reload
      await friendPage.reload();
      await expect(friendPage.getByRole("button", { name: /sign out/i })).toBeVisible();

      // 5. Friend logs out and signs back in via email magic link
      await friendPage.getByRole("button", { name: /sign out/i }).click();
      await expect(friendPage.getByRole("button", { name: /^sign in$/i })).toBeVisible();

      const knownFriendMailIds = await getExistingMessageIds(request, friendEmail);
      await friendPage.getByRole("button", { name: /^sign in$/i }).click();
      const friendDialog = friendPage.getByRole("dialog");
      await friendDialog.getByLabel(/email address/i).fill(friendEmail);
      await friendDialog.getByRole("button", { name: /email me a sign-in link/i }).click();
      await expect(
        friendDialog.getByText(/if an account exists, a sign-in link has been sent/i),
      ).toBeVisible();

      const friendMagicPath = await waitForMagicLinkUrl(request, friendEmail, knownFriendMailIds);
      await friendPage.goto(friendMagicPath);
      await friendPage.getByRole("button", { name: /sign in to yummy/i }).click();

      await expect(friendPage).toHaveURL(/\/$/);
      await expect(friendPage.getByRole("button", { name: /sign out/i })).toBeVisible();
    } finally {
      await friendContext.close();
    }

    // 6. Admin sees invite marked as claimed
    await page.reload();
    await expect(page.getByText("claimed").first()).toBeVisible();
  });
});
