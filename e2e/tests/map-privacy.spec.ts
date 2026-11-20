import { expect, test } from "@playwright/test";

test("Journey 4: default MapLibre view loads with zero Google requests and sidebar/sheet list tabs", async ({
  page,
}) => {
  const googleRequests: string[] = [];
  const workerErrors: string[] = [];
  page.on("request", (req) => {
    const url = req.url();
    if (url.includes("googleapis.com") || url.includes("gstatic.com")) {
      googleRequests.push(url);
    }
  });
  page.on("pageerror", (err) => workerErrors.push(err.message));
  page.on("console", (msg) => {
    if (msg.type() === "error" && msg.text().includes("Worker failed to load")) {
      workerErrors.push(msg.text());
    }
  });

  await page.goto("/");

  await expect(page.getByRole("link", { name: "yummy" })).toBeVisible();
  await expect(page.getByRole("searchbox", { name: /search places/i })).toBeVisible();
  await expect(page.getByRole("tab", { name: /spots/i })).toBeVisible();
  const listsTab = page.getByRole("tab", { name: /lists/i });
  await expect(listsTab).toBeVisible();
  await expect(page.getByTestId("maplibre-canvas")).toBeVisible();

  await listsTab.click();
  await expect(page.getByRole("searchbox", { name: /filter lists/i })).toBeVisible();

  expect(googleRequests).toEqual([]);
  expect(workerErrors).toEqual([]);
});
