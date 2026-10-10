# Contributing to Nova WhatsApp

Project rules (isolation between customers by PostgreSQL RLS, append-only logs, translations, no secrets…) are in `CLAUDE.md` and `docs/`. Read them before changing the data layer.

## 1. Work in a Codespace

1. Create a Codespace from `r4ladmin/Nova-Whatsapp` (see **Developing in GitHub Codespaces** in the README). The application is running when it opens (port 8000).
2. Never develop on `main` directly.

## 2. Create a branch

```bash
git switch main && git pull
git switch -c feature/short-description   # or fix/…, docs/…
```

Small, focused commits with a clear message (what changed and why). Keep unrelated changes in separate branches.

## 3. Develop with the provided commands

| Task | Command |
|---|---|
| Run the whole app (already started by the Codespace) | `npm run dev:start` / `npm run dev:restart` / `npm run dev:logs` |
| Migrations / demo data / reset | `composer migrate` · `composer seed` · `composer reset` |
| Back-end tests | `composer test` |
| Back-end quality (Pint, Larastan level 6, Pest) | `composer check` |
| Front-end quality (Prettier, ESLint, vue-tsc, Vitest) | `npm run check` |

Conventions: `declare(strict_types=1)`, Pint (Laravel style), Larastan level 6, Pest tests on real PostgreSQL, code by module in `app/Modules/*`, **no hard-coded text** (fr/nl/en translations in `lang/`), dates displayed as DD/MM/YYYY. Every business table has `org_id` and RLS (`Rls::enableTenantTable()`); logs are append-only.

## 4. Before opening a pull request

Run everything and fix what it reports:

```bash
composer check:all
```

(`composer check` = Pint + Larastan + Pest; `npm run check` = Prettier + ESLint + type-check + Vitest.) Also click through the screens you changed in the forwarded browser tab (and Mailpit for e-mails). New behaviour needs tests; a migration must be reversible and keep RLS and grants correct.

Then:

```bash
git push -u origin feature/short-description
gh pr create --fill   # or use the GitHub UI
```

Describe what changed, how you tested it, and anything the reviewer should look at (migrations, permissions, translations).

## 5. Never commit secrets

- Real credentials, API keys, database passwords, WhatsApp/Meta tokens, payment keys and production URLs **never** go in the repository, in `.env.example`, in tests, in docs or in screenshots.
- `.env` and every `.env.*` except `.env.example` are git-ignored. The values in `.env.example` are throwaway development values or empty on purpose.
- Use **Codespaces secrets** (Settings › Codespaces › Secrets) for real values: they override `.env` as environment variables. Local-only files (test accounts, notes) use the `*.local.txt` pattern, which is ignored.
- Before committing, check `git diff --staged` for anything that looks like a token or a password. If a secret is ever pushed, revoke it immediately: rewriting history is not enough.
- Never point the Codespace at production databases or live WhatsApp/payment accounts; use the simulated drivers (`WHATSAPP_DRIVER=fake`, `BILLING_GATEWAY=fake`) or sandbox/test accounts.
