# yummy

An open-source, privacy-first collaborative mapping PWA for dropping, sharing, and organizing custom food POIs with friends.

## Architecture & Specifications

All engineering specifications live in [`specs/`](./specs/):
- [Product Requirements (PRD)](./specs/PRD.md)
- [Technical Design Document (TDD)](./specs/TDD.md)
- [OpenAPI 3.1 Contract](./specs/api/openapi.yaml)
- [Architecture Decision Records](./specs/adr/)
- [UX & Design System](./specs/ux/design-system.md)
- [Testing & DevEx](./specs/testing-and-devex.md)

## Quick Start (DevContainer)

1. Open this repository in VS Code / DevContainers (`Reopen in Container`).
2. Services started automatically:
   - `app`: PHP 8.3 + Composer + Node 22 + Playwright deps
   - `mariadb`: MariaDB 11 (`yummy` & `yummy_test`)
   - `mailpit`: Local SMTP (`1025`) + Web UI & REST API (`http://localhost:8025`)
3. Inside the container:
   - Backend: `cd backend && composer install && php bin/console doctrine:migrations:migrate`
   - Frontend: `cd frontend && npm install && npm run dev`
   - E2E: `cd e2e && npm install && npx playwright test`
