import { defineConfig, devices } from "@playwright/test";
import { E2E_DATABASE_URL } from "./global-setup";

const E2E_BACKEND_URL = "http://127.0.0.1:8001";
const E2E_FRONTEND_URL = process.env.E2E_BASE_URL ?? "http://localhost:5174";

export default defineConfig({
  testDir: "./tests",
  globalSetup: "./global-setup.ts",
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: "html",
  use: {
    baseURL: E2E_FRONTEND_URL,
    trace: "on-first-retry",
  },
  webServer: [
    {
      command: "php -S 127.0.0.1:8001 -t public",
      cwd: "../backend",
      url: `${E2E_BACKEND_URL}/api/config`,
      reuseExistingServer: false,
      env: {
        APP_ENV: "dev",
        DATABASE_URL: E2E_DATABASE_URL,
        MAILER_DSN: process.env.MAILER_DSN ?? "smtp://mailpit:1025",
        APP_FRONTEND_URL: E2E_FRONTEND_URL,
      },
    },
    {
      command: "npx vite --port 5174 --strictPort --host 127.0.0.1",
      cwd: "../frontend",
      url: E2E_FRONTEND_URL,
      reuseExistingServer: false,
      env: {
        VITE_API_TARGET: E2E_BACKEND_URL,
      },
    },
  ],
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
    {
      name: "mobile-webkit",
      use: { ...devices["iPhone 14"] },
    },
  ],
});
