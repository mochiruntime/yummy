import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const backendDir = path.resolve(__dirname, "../backend");

export const E2E_DATABASE_URL =
  process.env.E2E_DATABASE_URL ??
  "mysql://yummy:yummy@mariadb:3306/yummy_test_e2e?serverVersion=11.4.0-MariaDB&charset=utf8mb4";

function runConsole(args: string[]): void {
  execFileSync("php", ["bin/console", ...args], {
    cwd: backendDir,
    stdio: "inherit",
    env: {
      ...process.env,
      APP_ENV: "dev",
      DATABASE_URL: E2E_DATABASE_URL,
      MAILER_DSN: process.env.MAILER_DSN ?? "smtp://mailpit:1025",
    },
  });
}

export default async function globalSetup(): Promise<void> {
  // Recreate isolated E2E database from scratch so dev DB stays untouched and runs are deterministic.
  runConsole(["doctrine:database:drop", "--force", "--if-exists"]);
  runConsole(["doctrine:database:create"]);
  runConsole(["doctrine:migrations:migrate", "--no-interaction"]);
  runConsole(["app:user:create-admin", "admin@example.com", "Admin"]);
  runConsole(["app:user:create-admin", "admin-poi@example.com", "Admin POI"]);
  runConsole(["cache:pool:clear", "cache.rate_limiter"]);
}
