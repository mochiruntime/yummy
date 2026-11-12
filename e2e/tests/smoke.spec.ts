import { expect, test } from "@playwright/test";

test("homepage renders without third-party Google requests", async ({ page }) => {
  const googleRequests: string[] = [];
  page.on("request", (req) => {
    if (req.url().includes("googleapis.com") || req.url().includes("gstatic.com")) {
      googleRequests.push(req.url());
    }
  });

  await page.goto("/");
  await expect(page.getByRole("heading", { name: "yummy" })).toBeVisible();
  expect(googleRequests).toEqual([]);
});
