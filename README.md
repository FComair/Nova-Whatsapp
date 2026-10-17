# Rendivoo

Plateforme SaaS multi-tenant (Laravel 13, PostgreSQL 16, Redis) : espace client et Super Admin, en français, néerlandais et anglais.

Voir `docs/` pour l'architecture, le schéma de base de données, les rôles et l'identité visuelle, et `CLAUDE.md` pour l'environnement Docker et les règles du projet.

## Developing in GitHub Codespaces

The repository ships a complete dev container (`.devcontainer/`): PHP 8.4, Node 24, PostgreSQL 16, Redis 7 and Mailpit. Nothing has to be installed locally and no secret is required to run the application: WhatsApp, billing and price-list reading run in simulated mode by default.

### 1. Create a Codespace

1. Open <https://github.com/r4ladmin/Rendivoo>.
2. **Code** › **Codespaces** › **Create codespace on main** (pick a machine with at least 2 cores / 4 GB).
3. Wait for the setup to finish (a few minutes the first time; the terminal shows each step). It installs the dependencies (`composer.lock`, `package-lock.json`), builds the assets, creates the databases, runs the migrations and loads the demo data, then starts the application.

### 2. GitHub secrets

**None is required.** Optional secrets (Settings › Codespaces › Secrets, scoped to this repository) override the development placeholders when you want to try a real integration. Never put them in a file of the repository.

| Secret | Purpose |
|---|---|
| `CONVERSATION_AI_API_KEY` | AI key for the native WhatsApp assistant and price-list reading (without it: menus only, `PRICELIST_DRIVER=fake` for a sample list). |
| `META_APP_ID`, `META_EMBEDDED_SIGNUP_CONFIG_ID` | Public identifiers of a Meta **test** app, for the embedded WhatsApp sign-up button. |
| `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET` | Meta webhook verification and signature (real WhatsApp tests only; also set `WHATSAPP_DRIVER=meta` in `.env`). |
| `N8N_SIGNING_SECRET` | Shared secret with a real n8n instance. |

### 3. Open the application

Port **8000** opens in your browser automatically (also in the **Ports** tab: *Rendivoo (application)*). All ports are **private** (only you can open them).

| Port | What |
|---|---|
| 8000 | The application (customer area, `/admin` for the Super Admin) |
| 5173 | Vite dev server (assets, hot reload), used by the page automatically |
| 8025 | Mailpit: every e-mail the app sends (verification, password reset, invoices) |

Demo account (development only, created by `DevelopmentSeeder`): `owner@rendivoo.test` / `nova-dev-only`, owner of "Salon Démo". Two-factor authentication is not enforced in the Codespace (turn it on in Settings › Security if you want to test it).
Sign-up works too: the e-mail arrives in Mailpit, the phone verification code is written to `storage/logs/laravel.log` (`NOVA_PHONE_CODE_DRIVER=log`).

### 4. Everyday commands

The application starts by itself with the Codespace (web server, queue worker and Vite). Its log is `/tmp/nova-dev.log`.

| Task | Command |
|---|---|
| Start / stop / restart the whole app in the background | `npm run dev:start` · `npm run dev:stop` · `npm run dev:restart` |
| Follow its log | `npm run dev:logs` |
| Run it in the foreground (web + queue + Vite) | `composer dev` (stop the background one first with `npm run dev:stop`) |
| Run the migrations | `composer migrate` |
| Load the demo data (idempotent) | `composer seed` |
| **Reset the local environment** (drop everything, migrate, reseed) | `composer reset` |
| Back-end tests (Pest, real PostgreSQL `nova_test`) | `composer test` |
| Back-end quality (Pint + Larastan + Pest) | `composer check` |
| Front-end quality (Prettier, ESLint, vue-tsc, Vitest) | `npm run check` |
| Everything | `composer check:all` |
| Rebuild the assets | `npm run build` |

Full environment rebuild: **Codespaces: Rebuild Container** from the command palette (the databases are kept; use `composer reset` to empty them).

More details, troubleshooting and the list of environment variables: [`.devcontainer/README.md`](.devcontainer/README.md). Contribution workflow: [`CONTRIBUTING.md`](CONTRIBUTING.md).
