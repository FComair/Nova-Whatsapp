# Dev container (GitHub Codespaces and VS Code Dev Containers)

Everything needed to build, run, test and debug Nova WhatsApp without installing anything locally. Works in **GitHub Codespaces** and in **VS Code Dev Containers** (Docker Desktop).

## What is included

| Piece | Version | Where it comes from |
|---|---|---|
| PHP (CLI + built-in server) with `pdo_pgsql`, `pgsql`, `intl`, `zip`, `bcmath`, `gd`, `pcntl`, `opcache`, `mbstring`, `redis`, `xdebug` | 8.4 | `.devcontainer/Dockerfile` (keep in line with `docker/php/Dockerfile`) |
| Composer | 2 | Dockerfile |
| Node + npm | 24 | dev container feature |
| Git, GitHub CLI, `psql` | latest | features / Dockerfile |
| PostgreSQL | 16 | `docker-compose.yml` (service `postgres`), roles and test database created by `docker/postgres/init/*.sql` |
| Redis (sessions, cache, queue) | 7 | service `redis` |
| Mailpit (e-mail catcher) | latest | service `mailpit` |

VS Code extensions: Intelephense, PHP Debug (Xdebug), Laravel Pint, Vue (Volar), ESLint, Prettier, Tailwind CSS, Vitest, PostgreSQL client, EditorConfig, GitHub Pull Requests.

The existing `docker-compose.yml` at the root is the **local Docker** setup (`docker compose up`); this folder is the **dev container** setup. They share the same service names (`postgres`, `redis`, `mailpit`), the same init scripts and the same `.env.example`.

## Lifecycle (what runs when)

| Hook | Script | What it does |
|---|---|---|
| `onCreateCommand` | `on-create.sh` | Creates `.env` from `.env.example` (never overwrites one), generates `APP_KEY`, prepares `storage/`. |
| `updateContentCommand` | `update-content.sh` | `composer install`, `npm ci`, `npm run build` (also used by Codespaces prebuilds). |
| `postCreateCommand` | `post-create.sh` | Waits for PostgreSQL, runs the migrations (owner role), loads the demo data. |
| `postStartCommand` | `post-start.sh` | Writes the Codespace URLs into `.env`, applies pending migrations, starts the app (`dev.sh start`). Set `NOVA_AUTOSTART=0` to skip the auto-start. |

`dev.sh start|stop|restart|status|logs` drives the background app (`npm run dev:all`: `php artisan serve` on 8000, `queue:work`, Vite on 5173). Log: `/tmp/nova-dev.log`.

## Ports (all private)

`forwardPorts`: **8000** app (opens automatically), **5173** Vite, **8025** Mailpit. PostgreSQL (5432) and Redis (6379) are **not** forwarded: they are reachable only from inside the dev container (`psql -h postgres -U nova_owner nova`). Xdebug listens on **9003** (see below).

Inside a Codespace, `post-start.sh` sets `APP_URL=https://<codespace>-8000.app.github.dev`, `TRUSTED_PROXIES=*`, `SESSION_SECURE_COOKIE=true` and the `VITE_*` addresses so that links, cookies and hot reload use the forwarded URLs. Nothing in the application code hard-codes `localhost`; `vite.config.ts` reads these variables and falls back to `localhost` for plain Docker.

To receive real Meta webhooks for a test, make port 8000 **public** temporarily (Ports tab › right click › Port Visibility › Public), set `WHATSAPP_DRIVER=meta` with Meta **test** credentials (as Codespaces secrets), and make it private again afterwards.

## Environment variables

`.env.example` documents every variable (purpose, required, secret). Development values are throwaway and safe to commit; real credentials are only ever provided as **Codespaces secrets** (a real environment variable beats `.env`). Optional secrets declared in `devcontainer.json`: `CONVERSATION_AI_API_KEY`, `META_APP_ID`, `META_EMBEDDED_SIGNUP_CONFIG_ID`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, `N8N_SIGNING_SECRET`. None is needed to run.

Set by `post-start.sh` in `.env` (development behaviour, never committed): `NOVA_REQUIRE_2FA=false`, `NOVA_ADMIN_REQUIRE_MFA=false`, `WHATSAPP_DRIVER=fake`, `BILLING_GATEWAY=fake`, plus the URL variables above. Change them in `.env` if you need a different behaviour, then restart (`npm run dev:restart`); the first four are re-applied at every start, so use a Codespaces secret/real environment variable for lasting overrides.

## Local commands

See the README table. In short: `composer dev` (foreground app), `composer migrate`, `composer seed`, `composer reset`, `composer test`, `composer check`, `npm run check`, `composer check:all`.

## Database

- Three roles (RLS design): `nova_owner` (migrations), `nova_app` (the application, subject to Row-Level Security), `nova_platform` (Super Admin). Created by `docker/postgres/init/01-roles-and-extensions.sql`, with the extensions `btree_gist`, `pgcrypto`, `citext`.
- Databases: `nova` (development) and `nova_test` (automated tests; the test suite migrates it itself).
- Migrations always run with `--database=pgsql_owner` (`composer migrate`).
- Demo data: `database/seeders/DevelopmentSeeder.php` creates "Salon Démo" with the owner `owner@nova.test` / `nova-dev-only`, a service, a practitioner, opening hours and a customer. It refuses to run outside `APP_ENV=local` and is idempotent.
- Super Admin (separate guard, TOTP required unless `NOVA_ADMIN_REQUIRE_MFA=false`): `php artisan nova:create-admin <email>`; in development (`APP_ENV=local`) `php artisan nova:dev-admin-link` prints a signed, single-use link (valid 10 minutes, local addresses only) that signs the first Super Admin in.
- Reset: `composer reset` (migrate:fresh + seed). To also recreate the roles and the test database, delete the volume: stop the app, `docker volume rm <project>_pgdata` (find it with `docker volume ls`) from a terminal outside the container, then rebuild the container.

## Debugging with Xdebug

Xdebug is installed but off. In the terminal: `XDEBUG_MODE=debug php artisan serve ...` (or set `XDEBUG_MODE=debug` in `containerEnv` and rebuild), start "Listen for Xdebug" in VS Code (port 9003) and trigger with the usual browser extension or `XDEBUG_TRIGGER=1`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Port 8000 shows "502" or nothing | The first request after start is slow (the built-in server). Check `npm run dev:logs`; restart with `npm run dev:restart`. |
| Page loads but styles/scripts are missing | Vite is not reachable: check the 5173 port is forwarded and `npm run dev:logs`; or `npm run build` (the app then works without Vite after you stop it: `npm run dev:stop`, remove `public/hot`). |
| "PostgreSQL ne répond pas" | `docker ps` shows the `postgres` container? Rebuild the container; if the volume is corrupted, remove it (see Database). |
| `SQLSTATE… permission denied` / RLS errors on a fresh volume | The init scripts only run on a **new** volume. Remove the volume and rebuild so roles and grants are created. |
| Login redirects in a loop or says the session expired | Open the app through the forwarded `https://…-8000.app.github.dev` URL (not `localhost`), and make sure `.env` has `APP_URL` equal to it (`bash .devcontainer/post-start.sh`). |
| No e-mail received | Open the Mailpit port (8025). Phone codes are in `storage/logs/laravel.log`. |
| `composer`/`npm` out of date after pulling | `bash .devcontainer/update-content.sh`. |
| Tests fail with "database does not exist" | `nova_test` is created with the volume: remove the volume and rebuild. |
| Everything is broken | **Codespaces: Rebuild Container**, then `composer reset`. |
