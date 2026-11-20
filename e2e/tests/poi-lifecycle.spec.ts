import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { expect, test } from "@playwright/test";
import { E2E_DATABASE_URL } from "../global-setup";
import { getExistingMessageIds, waitForMagicLinkUrl } from "./helpers/mailpit";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ADMIN_EMAIL = process.env.E2E_POI_ADMIN_EMAIL ?? "admin-poi@example.com";

test.describe("Journey 2: Lists, + Add place, Pin Drop, Optimistic Edit & Revision Revert", () => {
  test.beforeAll(() => {
    execFileSync("php", ["bin/console", "cache:pool:clear", "cache.rate_limiter"], {
      cwd: path.resolve(__dirname, "../../backend"),
      env: { ...process.env, APP_ENV: "dev", DATABASE_URL: E2E_DATABASE_URL },
    });
  });
  test("admin creates lists with emoji, drops pin via + Add place, friend edits, and admin reverts", async ({
    page,
    browser,
    request,
  }, testInfo) => {
    const suffix = `${Date.now()}-${testInfo.project.name}-${testInfo.parallelIndex}`;
    const list1Name = `Bakeries ${suffix}`;
    const list2Name = `Ramen ${suffix}`;
    const poiName = `Atelier ${suffix}`;
    const originalDesc = `Original sourdough ${suffix}`;
    const editedDesc = `Edited pistachio croissant ${suffix}`;
    const friendEmail = `friend-poi-${suffix}@example.com`;

    // 1. Admin signs in via Mailpit magic link
    const knownAdminMailIds = await getExistingMessageIds(request, ADMIN_EMAIL);
    await page.goto("/");
    await page.getByRole("button", { name: /^sign in$/i }).click();

    const signInDialog = page.getByRole("dialog");
    await signInDialog.getByLabel(/email address/i).fill(ADMIN_EMAIL);
    await signInDialog.getByRole("button", { name: /email me a sign-in link/i }).click();
    await expect(
      signInDialog.getByText(/if an account exists, a sign-in link has been sent/i),
    ).toBeVisible();

    const adminMagicPath = await waitForMagicLinkUrl(request, ADMIN_EMAIL, knownAdminMailIds);
    await page.goto(adminMagicPath);
    await page.getByRole("button", { name: /sign in to yummy/i }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("button", { name: /sign out/i })).toBeVisible();

    // 2. Admin creates 2 lists (including emoji selection)
    await page.getByRole("tab", { name: /lists/i }).click();
    await page.getByRole("button", { name: /add list/i }).click();

    await page.getByLabel(/list name/i).fill(list1Name);
    await page.getByRole("button", { name: "Select emoji 🥐" }).click();
    await page.getByRole("button", { name: /create list/i }).click();
    await expect(page.getByText(list1Name).first()).toBeVisible();

    await page.getByLabel(/list name/i).fill(list2Name);
    await page.getByRole("button", { name: "Select emoji 🍜" }).click();
    await page.getByRole("button", { name: /create list/i }).click();
    await expect(page.getByText(list2Name).first()).toBeVisible();

    await page.getByRole("button", { name: /close list manager/i }).click();

    // 3. Admin clicks + Add place -> Pick on map -> clicks map -> fills form & saves
    await page.getByRole("button", { name: /add place/i }).click();
    await expect(page.getByRole("heading", { name: /add place/i })).toBeVisible();

    await page.getByRole("button", { name: /pick on map/i }).click();
    await expect(page.getByText(/tap anywhere on the map/i)).toBeVisible();

    const canvas = page.locator("canvas").first();
    const box = await canvas.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      await page.mouse.click(box.x + 60, box.y + 80);
    }

    await expect(page.getByRole("heading", { name: /add place/i })).toBeVisible();
    await expect(page.getByText(/coordinates:/i)).toBeVisible();

    await page.getByLabel(/^name/i).fill(poiName);
    await page.getByLabel(/address/i).fill("12 Rue de Rivoli, Paris");
    await page.getByLabel(/description/i).fill(originalDesc);
    await page.getByRole("combobox", { name: /add to your lists/i }).click();
    await page.getByRole("option", { name: new RegExp(list1Name) }).click();
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: /save place/i }).click();

    // Detail panel opens with Friends Only default
    await expect(page.getByRole("heading", { name: poiName })).toBeVisible();
    await expect(page.getByText(originalDesc)).toBeVisible();

    // Extract POI ID from API or navigate to /pois/:id? Better: grab it from the spots list or API
    const poisResponse = await page.request.get("/api/pois");
    const poisList = (await poisResponse.json()) as Array<{ id: string; name: string }>;
    const createdPoi = poisList.find((p) => p.name === poiName);
    expect(createdPoi).toBeDefined();
    const poiPath = `/pois/${createdPoi!.id}`;

    // 4. Admin creates an invite for a second member
    await page.getByRole("link", { name: /invites/i }).click();
    await expect(page).toHaveURL(/\/invites$/);
    await page.getByRole("button", { name: /generate invite link/i }).click();
    const inviteUrlInput = page.getByLabel(/generated invite url/i);
    await expect(inviteUrlInput).toBeVisible();
    const invitePath = new URL(await inviteUrlInput.inputValue()).pathname;

    // 5. Friend claims invite, opens /pois/:id, edits description
    const friendContext = await browser.newContext();
    const friendPage = await friendContext.newPage();
    try {
      await friendPage.goto(invitePath);
      await friendPage.getByLabel(/display name/i).fill("Sam Editor");
      await friendPage.getByLabel(/email address/i).fill(friendEmail);
      await friendPage.getByRole("button", { name: /join yummy/i }).click();
      await expect(friendPage).toHaveURL(/\/$/);

      await friendPage.goto(poiPath);
      await expect(friendPage.getByRole("heading", { name: poiName })).toBeVisible();
      await friendPage.getByRole("button", { name: /^edit$/i }).click();
      await friendPage.getByLabel(/description/i).fill(editedDesc);
      await friendPage.getByRole("button", { name: /save changes/i }).click();
      await expect(friendPage.getByText(editedDesc)).toBeVisible();
    } finally {
      await friendContext.close();
    }

    // 6. Admin opens /pois/:id, opens History, and reverts to v1
    await page.goto(poiPath);
    await expect(page.getByText(editedDesc)).toBeVisible();

    await page.getByRole("button", { name: /^history$/i }).click();
    await expect(page.getByRole("heading", { name: new RegExp(`history · ${poiName}`, "i") })).toBeVisible();
    await expect(page.getByRole("list", { name: /revision history/i }).getByText("v2")).toBeVisible();

    await page.getByRole("button", { name: /revert to v1/i }).click();
    await expect(page.getByText(originalDesc)).toBeVisible();
  });
});
