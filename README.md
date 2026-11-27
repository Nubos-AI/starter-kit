# Nubos Starter Kit

A Laravel starter kit for multi-tenant business applications. Start with teams, permissions and a flexible data model — and make it your own.

**Documentation:** [English](https://starterkit.nubos.cloud/en/v1/introduction.html) · [Deutsch](https://starterkit.nubos.cloud/v1/introduction.html)

## What you get

- **Multi-tenancy** with teams and sub-teams, a tenant boundary on every query and a team segment in every URL
- **Roles and permissions** down to field level, plus SSO and SCIM provisioning
- **Runtime data model**: object types, custom fields, relationships, formulas, lists, reports and dashboards
- **Integrations**: REST API with access tokens, signed webhooks, import and export
- **Background processing** with Temporal workflows instead of the Laravel queue
- **Modules**: optional free packages (`nubos/admin`, `nubos/automations`, `nubos/demodata`, `nubos/documents`, `nubos/pipelines`, `nubos/sandbox`) installed through Composer
- **Quality tooling**: Larastan (level 7), Pint, Pest, Vitest, ESLint and vue-tsc

Stack: Laravel 13, PHP 8.5, Inertia 3, Vue 3 with TypeScript, Tailwind CSS 4, PostgreSQL 18, Octane with Swoole.

## Requirements

- Git and Docker with Docker Compose v2
- macOS, Linux or Windows with WSL2

You do not need PHP or Node.js on the host. The project builds its own Sail image from `docker/8.5/Dockerfile` with PHP 8.5, Node.js 24 and the RoadRunner binary for the Temporal worker.

## Quickstart

```sh
git clone https://github.com/Nubos-AI/starter-kit.git nubos-starterkit
cd nubos-starterkit
cp .env.example .env

export WWWUSER=$(id -u)
export WWWGROUP=$(id -g)
docker compose build laravel.test
docker compose run --rm --no-deps laravel.test composer install --no-scripts

alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
sail up -d laravel.test temporal-ui
sail artisan key:generate
sail artisan migrate
sail composer dump-autoload
sail restart laravel.test

sail npm ci
sail npm run build
sail artisan octane:reload
sail up -d temporal-worker
```

Open `http://localhost`. To get example tenants, users and object types to log in with, install the demo data package and follow [Getting Started](https://starterkit.nubos.cloud/en/v1/getting-started.html):

```sh
sail composer require nubos/demodata
```

The full walkthrough, including the Laravel installer (`laravel new my-app --using=nubos/starterkit --phpunit`) and common pitfalls, is in the [installation guide](https://starterkit.nubos.cloud/en/v1/installation.html).

## Services

| Service | Purpose | Required? |
| --- | --- | --- |
| `laravel.test` | Application (Octane with Swoole) and the Vite port | Yes |
| `pgsql` | PostgreSQL 18 | Yes |
| `temporal` | Temporal server | Yes |
| `temporal-worker` | Runs workflows and activities | Yes, otherwise import, export, mails, webhooks and search sync do not run |
| `meilisearch` | Search index | For global search |
| `mailpit` | Mail catcher, `http://localhost:8025` | Locally, as soon as mails are sent |
| `temporal-ui` | Temporal web UI, `http://localhost:8233` | Optional, for debugging |
| `redis` | Redis | Optional, cache and sessions use the database by default |
| `rustfs` | S3-compatible storage | Optional, only used once the `AWS_*` values and `FILESYSTEM_DISK` are set |

The defaults in `.env.example` already point at these services. The [configuration page](https://starterkit.nubos.cloud/en/v1/starterkit/configuration.html) lists the remaining environment variables.

## Architecture at a glance

| Layer | Where | Role |
| --- | --- | --- |
| Routes | `routes/web.php`, `routes/app.php`, `routes/api.php` | Team-prefixed web routes, API routes |
| Controllers | `app/Http/Controllers/` | HTTP only: authorize first, then call an Action |
| Actions | `app/Actions/` | Business logic, validation and all writes |
| Workflows | `app/Workflows/`, `app/Activities/` | Multi-step processes on Temporal, one Action per activity |
| Models | `app/Models/` | Data, relationships and scopes, tenant-scoped |
| Modules | `vendor/nubos/*` | Optional packages, registered with `modules:sync` |
| Frontend | `resources/js/domains/`, `resources/js/shared/` | Vue pages and components rendered through Inertia |

Read more in [Architecture](https://starterkit.nubos.cloud/en/v1/starterkit/architecture.html), [Tenancy](https://starterkit.nubos.cloud/en/v1/starterkit/tenancy.html), [Permissions](https://starterkit.nubos.cloud/en/v1/starterkit/permissions.html), [Modules](https://starterkit.nubos.cloud/en/v1/starterkit/modules.html) and the [Concept Overview](https://starterkit.nubos.cloud/en/v1/concept-overview.html).

## Checks

```sh
sail composer ci:check
```

Runs ESLint, the format check, vue-tsc, Vitest, Pint, PHPStan and Pest — the same command the GitHub Actions workflow in `.github/workflows/tests.yml` runs in applications created from the kit. See [Tests](https://starterkit.nubos.cloud/en/v1/starterkit/tests.html) for the testing approach.

## License

MIT
