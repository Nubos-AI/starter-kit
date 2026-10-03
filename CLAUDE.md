<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Coding Standards (mandatory)

The consolidated Nubos standard lives in the **`nubos-coding-standards`** skill
(`.claude/skills/nubos-coding-standards/SKILL.md`). Activate it for every coding
task — implementing, fixing, testing, reviewing. It inlines the core rules and
routes to the detailed conventions, templates, and known pitfalls. Where it is
stricter than the Boost guidelines above, the stricter rule applies.

Golden rules (always, even without the skill loaded):

- Read the ENTIRE class before modifying it. Follow its existing patterns. Never
  create a second way to do the same thing.
- No duplicate code — >3 shared lines → extract now (Action/Service, Trait, or
  private method). Never "refactor later". Remove dead code and unused imports.
- Business logic lives in Actions. Controllers = HTTP only (`$this->authorize()`
  FIRST). Services = infrastructure only. Models = data only (relationships,
  scopes, accessors). Cross-domain communication → transactional outbox
  (`OutboxEvent`, drained by `OutboxRelay` into Temporal activities), never
  direct calls and never a Laravel Event/Listener — there is no `app/Events/`.
- All model CRUD inside Actions, always via `Model::query()->…` — never
  `Model::create()` etc. Actions validate via `Validator::make(...)->validate()`;
  FormRequests only for non-CRUD endpoints without an Action.
- Models: `HasUlids` + `SoftDeletes` + explicit `#[Fillable]` (never
  `#[Guarded([])]`, field order = migration order) + `casts()` method (omit when
  empty) + `#[ScopedBy([TenantScope::class])]` + `#[UsePolicy(...)]` on every
  API-exposed model.
  Primary keys are ULID — `HasUuids` is forbidden (Project Rules, D5.1).
  Accessors via `Attribute::make()` — property hooks don't work on Eloquent.
- Migrations: column order `id → FKs → unique → booleans → fields → softDeletes
  → timestamps`; `$table->ulid('id')->primary()` and `foreignUlid()` (never
  `uuid()`/`foreignUuid()`); `idx_{table}_{columns}` naming; string columns
  for enums; `down()` is always reversible.
- `declare(strict_types=1)` everywhere; no explanatory comments (type docblocks
  only); `!$x` without space; no `const` and no `final` (Enums
  or properties instead); `env()` only in config files; no secrets anywhere.
- Every API response through an Eloquent Resource (camelCase JSON,
  `whenLoaded()`, ISO-8601, ULIDs as strings). Every endpoint: authorization
  (`EnsurePermission` route middleware or a Policy check) + tests.
- Vue: `<script setup lang="ts">`, `defineModel()`, `useTemplateRef`, Pinia for
  global state, TS strict with no `any`, enums as `as const` objects.
- Known pitfalls (details in the skill): paginate on the Builder, never on a
  Collection; UTC everywhere; new columns must be added to `#[Fillable]` (silent
  drop!); partial unique index with SoftDeletes; `orderBy('id')` is chronological
  here because keys are ULID — it stops being so the moment a table uses UUID,
  which D5.1 forbids; cleanup for every listener in `onUnmounted`.
- Done = Larastan clean + Pint + all tests green (run via Sail, see above).
- Git: `feature/{TICKET}-{desc}`, Conventional Commits, squash-merge, PR < 400
  lines.
  
## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `vendor/bin/sail artisan make:test --pest SomeFeatureTest` instead of `vendor/bin/sail artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

## Project Rules (mandatory)

Project-wide always-follow rules for every task. Where they are stricter than the
Boost guidelines or the `nubos-coding-standards` skill, these rules win.

### Always-Follow

- Run every PHP, Artisan, Composer and Node command through Sail (`vendor/bin/sail …`). Host `npm`/`npx` is broken by design here — `node_modules` is the container mount and carries linux-arm64 native bindings only.
- All six quality gates must be green before a task is reported done: `vendor/bin/sail artisan test --compact` (Pest) · `vendor/bin/sail composer test:packages` (Pest per installed module, own `phpunit.xml`) · `vendor/bin/sail npm run test` (Vitest) · `vendor/bin/sail composer types:check` (PHPStan level 7, **no baseline**, 0 errors) · `vendor/bin/sail npx eslint .` + `vendor/bin/sail npm run format:check` · `vendor/bin/sail npm run types:check` (vue-tsc). `types:check` is the one that gets forgotten — no other gate covers it.
- Run `vendor/bin/sail bin pint --format agent <paths>` on every changed PHP file after each edit, passing the paths explicitly — `--dirty` misses untracked files. Never `pint --test`.
- Identifiers, schema, code and commit messages are **English** — classes, methods, variables, tables, columns, enum cases, plus every server-side message (exceptions, validation, audit summaries). **User-visible copy is German** — labels, buttons, headings, card descriptions, empty states, toasts, dialog text; see Conventions › UI / UX. That includes the copy PHP produces when it reaches the screen unchanged: enum `label()` values, navigation labels, `Inertia::flash('toast', …)` messages, select-option labels handed to a page, and export headers and notes. Nothing else in PHP or TS carries German.
- Write the failing test **first**, run it, confirm it fails for the right reason, then implement until green — for fixes as well as features. Show the red→green transition. Never add tests after the implementation already works and present them as TDD.
- Read the whole class plus its traits and parents before modifying it. Reuse existing methods and patterns; never introduce a second way of doing the same thing, and never mix two patterns inside one class.
- One atomic commit per logical change, as a Conventional Commit with a domain scope (`feat(engine): …`, `fix(authorization): …`).
- All asynchronous work is modelled as a Temporal workflow, activity or schedule. `QUEUE_CONNECTION=sync` is the safety net, not a target — see Forbidden.
- Primary keys are **ULID** everywhere: `HasUlids` on the model, `$table->ulid('id')->primary()` and `$table->foreignUlid(...)` in migrations. Never `HasUuids`/`uuid()`/`foreignUuid()` (locked decision D5.1).
- Every model gets a factory (for seeders and local data). Endpoints, Actions and Services get Pest tests that run without a database — see Conventions › Tests.
- Configuration and schema tables (`object_types`, `relationship_types`, and their twelve siblings) are tenant-scoped like every other configuration table — they carry `tenant_id`, `#[ScopedBy([TenantScope::class])]`, and `BelongsToTenant`, and their uniqueness is always tenant-qualified. A resolution path with no bound tenant (seeder, console command, Temporal activity) must call `withoutTenantScope()` explicitly (D-01 from M006, 2026-09-07).
- Tunable values live in `config/` (e.g. `config/engine.php`), read via `config()`. `env()` is called only inside `config/`.

### Forbidden

- **No `const` — anywhere, including test files.** Every finite value-set becomes an **enum**; every tunable becomes a class property (instance property for internal use, `public static` when other classes or tests read it) or a `config/` entry. `grep -rnE '^\s*(private|protected|public)?\s*const\s' app database tests` must return 0. A `const` used as a method default parameter or inside an attribute is not an exception — restructure instead.
- **No `final`** on classes or methods. Plain `class`, `abstract class`, `readonly class`; `public function`, never `final public function`.
- **No explanatory comments and no prose docblocks.** Only type-bearing PHPDoc is allowed: `@param`, `@return`, `@throws`, inline `/** @var */`, array shapes, `@extends`/`@implements`/`@template`. `grep -rnE '^\s*\* [A-Za-z]' app/ | grep -vE '\* @'` must return 0. Never `@return void`.
- **No Laravel Queue as an execution path** — no `ShouldQueue`, no `Bus::batch`, no queued listeners, no queued notifications, no `dispatch()` of a job as the async mechanism. Async work is Temporal only.
- **No `add_*` / `alter_*` / `*_to_*_table` migrations before the first release.** To add or change a column, edit that table's original `create_*_table` migration. Delete any ADD migration an agent generates. If the referenced table is created later in the sequence, add a nullable indexed ULID column without a DB FK constraint rather than reintroducing an ALTER.
- **No Eloquent models, queries or container calls inside migrations.** Migrations are schema-only. Data or index creation that depends on records belongs in the responsible runtime Action or a console command.
- **No destructive database command without explicit consent in the same conversation** — `migrate:fresh`, `migrate:refresh`, `db:wipe`, `migrate:rollback`, any drop or truncate. When an edited CREATE migration needs a fresh run, stop and ask, and offer a `pg_dump` first. There is no backup package installed and no undo.
- **No hard-coded role names.** Roles are dynamic DB data. Identify privileged roles through an enum-typed discriminator column set by the seeder, never by matching name strings.
- **No concrete domain entities in the core** — no Deal, Projekt, Angebot, Kontakt or equivalent tables, models or handlers. The platform ships generic building blocks; concrete business objects belong to apps built later on top. Challenge any plan step that drifts this way before executing it.
- **No tests that read a seeder instance's state.** Seeders inherit only the base `Seeder` and are standalone. Shared test data lives in `tests/Fixtures/` (namespace `Tests\Fixtures`).
- No `env()` outside `config/`. No `DB::` calls in models. No business logic in controllers or models.
- No `protected $table`, `$keyType` or `$incrementing` on models. Table names must be Laravel-derivable; use the `#[Table('…')]` attribute only when a non-derivable name is genuinely unavoidable.
- No `--ignore-platform-req`, no `--no-verify`, no `--force` in any pipeline command. No `git push --force` against `main`.
- No `timeout` in Sail commands — macOS has no coreutils `timeout`.
- No skipped or weakened tests to reach green. A `skip()` without a named reason is a defect; a commented-out assertion is a defect.

### Dependencies

- Never add, upgrade or remove a dependency without explicit approval. A plan step that names a package is approval for that package only.
- Install through Sail (`vendor/bin/sail composer require …`, `vendor/bin/sail npm install …`) so the lockfile is resolved against the container's PHP and platform, never the host's.
- Commit the lockfile with the manifest in the same commit. Constraint style follows the neighbouring entries (caret); `sort-packages` decides the ordering — never hand-sort.
- A new dependency requires a written note covering: resolved version, licence **as reported by the tool** (never from memory), transitive additions, required PHP/Node extensions, and maintenance state (last release, open issues that affect the intended use).
- A `composer require` exit code other than 0 is not automatically a resolver conflict — the `nubos/module-installer` plugin runs `modules:sync` after the autoload dump and fails when that fails. Verify with `composer show <package>` before concluding anything.
- Removing a module: `vendor/bin/sail composer remove nubos/<module>` — the plugin runs `modules:unregister`, i.e. the module's `uninstall` command, before Composer deletes the files. Never delete a module's files by hand.
- Prefer a framework or standard-library capability over a new package. A package that would be used in one place, for one call, is not a dependency — write the call.

### Security

- Authorization is the **first** statement in every controller method: `$this->authorize('ability', $model)`. A method without it is a defect, not an oversight.
- Never rely on implicit route-model binding for a tenant-owned resource whose model lacks a global tenant scope. Resolve it explicitly — `->where('tenant_id', $actingUser->tenant_id)->whereKey($id)->firstOrFail()` — and type the route parameter as `string`. `User` has **no** `TenantScope`; binding it implicitly is a cross-tenant IDOR.
- Field-level visibility is enforced server-side through the field-permission/visibility resolver on every read path — list, detail, export, document render, API. A field hidden in the UI but present in the payload is a leak.
- **Record-level visibility: the tenant is the boundary, not the owner** (locked 2026-08-05). Every user sees every record of their tenant unless a role narrows it; `owner_id` records responsibility, not access, and never gates a read. Who may change a record is decided by the object-type permissions (`{slug}.update`, `{slug}.delete`), which are enforced independently of visibility. Deriving a narrower level from ownership is a defect. The narrowing apparatus was removed on 2026-08-05 (`VisibilityScope`, `VisibilityLevel`, `VisibilityLevelResolver`, `TeamVisibilityResolver`, `ResolveVisibilityContext` and the `current_visibility` binding); the tenant scope carries the boundary alone. `Role::grants_subteam_visibility` survives with a narrower meaning: it governs how far a manager reaches into subteams in **user administration** (`ManagedUserResolver`), never record visibility.
- Every outbound HTTP call to a user-supplied target passes the SSRF guard (pinned resolution, loopback and metadata addresses rejected). User-supplied HTML is never handed to a renderer that resolves `<img src>` or external references without an allowlist.
- Any identifier that a user controls and that reaches a regex, a query, a path or a shell argument is validated against an explicit allowlist pattern before use — never escaped ad hoc, never interpolated raw.
- Secrets resolve from env via `config/` only. Never inline a credential, token or signing key — not in source, not in tests, not in fixtures, not in seeders.
- Webhook and API payload signatures are verified with a constant-time comparison including the timestamp; reject outside the replay window.
- Rate-limit and idempotency-protect every state-mutating public endpoint.
- A permission or visibility change ships with a test that proves the **denied** path, not only the allowed one.

### Logging & Observability

- Never log passwords, tokens, signing secrets, session identifiers, full request bodies, or field values from records whose visibility is restricted. Log identifiers and counts, not payloads.
- Every state-mutating Action records its audit and outbox entry through `AuditRecorder` inside its own transaction; audit entries are never assembled ad hoc in a controller.
- Errors are typed and surfaced. No `catch` that swallows — log with context and rethrow, or convert to a typed domain exception the caller handles. A caught exception that produces no log line and no rethrow is a defect.
- A failure the user can act on surfaces as a `ValidationException::withMessages()` keyed to a field the form actually renders, with a matching `<InputError>` in the Vue component. An exception with no bound error key reaches the UI as a silent failure.
- Every Temporal activity and workflow logs its terminal outcome — success and failure alike — with the workflow/run identifier. A run whose status is never finalized is the failure mode this rule exists to prevent.
- New service, handler, workflow and integration paths are diagnosable from the log alone: entry, external call, terminal outcome. If reproducing a failure requires adding a log line, the path was under-instrumented.

### Conventions

#### Class / Module Structure

- **The class TYPE picks the top-level `app/` bucket; the DOMAIN is a subfolder** — `app/{Type}/{Domain}/…`. Never create a top-level domain folder (`app/CustomFields/`), and never dump a whole domain into one bucket. Canonical homes: Actions → `app/Actions/{Domain}/`, interfaces → `app/Contracts/{Domain}/`, exceptions → `app/Exceptions/{Domain}/`, abstract bases → `{Parent}/Abstracts/`, strategy/handler implementations → `app/Handlers/{Domain}/`, registries and validators and other support services → `app/Support/{Domain}/`, enums → `app/Enums/{Domain}/`, traits → `app/Traits/{Domain}/` (**never** `app/Concerns/`), DTOs → `app/DTOs/{Domain}/`, Temporal workflows → `app/Workflows/{Domain}/`, activities → `app/Activities/{Domain}/`.
- **Layering is strict and top-down: Controller → Validation/Authorization → Action → Service/Repository.** Models and Services must not call Actions. Controllers must not call Services directly. Cross-domain communication goes through the transactional outbox (`OutboxEvent` written by `AuditRecorder`, drained by `OutboxRelay` into Temporal workflows), never a direct Action call into another domain. This project has no Laravel `Event`/`Listener` classes and does not add any.
- **Actions are the only home for business logic and for all model CRUD.** `{Verb}{Resource}Action` in `App\Actions\{Domain}\` with one `execute()` entry method. The Action owns its validation (`Validator::make($data, $rules)->validate()`) and its DB transaction; side effects that leave the domain are recorded in the outbox inside that transaction, never dispatched as a Laravel event. Never create, update or delete a model from a controller, service, command, workflow or listener.
- **Controllers are thin** — HTTP only. Single-action `__invoke()` for non-CRUD, resource controllers only for full CRUD. Web returns `Inertia::render()`, API returns a `JsonResponse` built from an API Resource — never `$model->toArray()`. Naming `{Resources}Controller` / `{Resource}{Action}Controller` in `App\Http\Controllers\{Domain}\`.
- **Models carry data only** — relationships, scopes, accessors, casts. No business logic, no `DB::`. `HasUlids` + `SoftDeletes`. Attribute tags, not properties: `#[Fillable([...])]` (never `#[Guarded]`, field order matching the migration), `#[Hidden]`, `#[Appends]`, `#[ScopedBy]`, `#[UsePolicy]`, `#[ObservedBy]`. Casts in the `casts(): array` method, omitted entirely when empty; enum columns cast to their enum. Computed accessors via `Attribute::make(get: …)`. Sibling exemplar: `app/Models/ObjectType.php`.
- **Class anatomy, in this order:** `<?php` → blank line → `declare(strict_types=1);` → blank line → namespace → alphabetically sorted imports → class. Members: traits → properties → constructor → methods, public before protected before private within each group. One trait per `use` statement.
- **Constructor injection only** — no `app()`, no `resolve()`, no service locator, no singleton carrying business logic. Use property promotion. Never leave an empty zero-parameter constructor.
- Abstract base classes are introduced only once there are three or more concrete implementations. Prefer composition over inheritance for reuse.
- DTOs are typed and immutable and carry data between layers instead of arrays.
- **Vue:** always `<script setup lang="ts">`, single root element, props via `defineProps<{…}>()` generics, emits via the named-tuple `defineEmits<{ 'event-name': [payload: Type] }>()`. Components in `resources/js/components/{Domain}/`, pages in `resources/js/pages/{Domain}/` mirroring the route structure. Template over ~100 lines gets sub-components extracted. Composables are the frontend equivalent of Actions — `use{Feature}`, called at top level, explicit return type. No API calls from components; navigation via `router.visit()` / `<Link>`, never `window.location`. Reuse the shared primitives (`DataGrid`, `CreateButton`, `DeleteButton`, `ConfirmDialog`) before writing a new one.

#### Naming

- Everything is **English** — classes, methods, variables, tables, columns, enum cases, test data, and every string literal that is not user-visible copy. German identifiers never exist.
- PHP: classes PascalCase, methods and variables camelCase. **Enum keys are TitleCase** (`Mister`, `Monthly`, `Unknown`) — never SCREAMING_CASE.
- Boolean-returning methods read as predicates: `is…`, `has…`, `can…`, `should…`. Never `flag`, never `status` for a boolean.
- Descriptive over terse: `isRegisteredForDiscounts()`, not `discount()`.
- Table names must be derivable as `snake_case(pluralStudly(ClassName))`. Columns are snake_case; foreign keys are `{singular}_id`.
- Actions are `{Verb}{Resource}Action`, controllers `{Resources}Controller` or `{Resource}{Action}Controller`, handlers `{Concept}Handler`, registries `{Concept}Registry`, resolvers `{Concept}Resolver`, DTOs `{Concept}Data` or `{Concept}Dto` consistently with existing siblings.
- Vue: components PascalCase and multi-word, used PascalCase in templates. Props camelCase in script, kebab-case in the parent template. Events kebab-case with a descriptive verb. Composables `use{Feature}` with the filename matching the exported function. Pinia stores `{name}Store` without a `use` prefix. Types and interfaces PascalCase in `resources/js/types/{Domain}.ts`.
- Migrations are numbered **sequentially** until the first release — a continuous counter ordered by FK dependency, never a date stamp, never a gap or a duplicate. The four-digit prefix names the origin:
  - **Core** (`database/migrations/`): `0001_01_01_NNNNNN_*.php`, counting from `000000`.
  - **Free package** (`"license"` in the package `composer.json` is anything other than `LicenseRef-Nubos`): `1PPP_01_01_NNNNNN_*.php`.
  - **Paid package** (`"license": "LicenseRef-Nubos"`): `2PPP_01_01_NNNNNN_*.php`.
  - Each package owns one fixed prefix; its counter restarts at `000000`. A new package takes the next free number in its block — never reuse or renumber an assigned prefix. Assigned: `1001` admin · `1002` pipelines · `1003` automations · `1004` documents · `1005` sandbox · `2001` application.
  - Core never depends on a package table, so core always runs first. Guarded by `tests/Unit/Architecture/MigrationNumberingTest.php`.

#### Code Style

- `declare(strict_types=1);` in every PHP file (Pint enforces it). Explicit return types and parameter type hints on every method.
- **No comments in source.** Only type-bearing PHPDoc survives (`@param`, `@return`, `@throws`, `/** @var */`, array shapes, generics). Blank line before `@return`; never `@return void`; omit the docblock entirely when there is nothing typed to say. Names and tests carry intent.
- `::query()` on every Eloquent database call — `CustomRecord::query()->create(...)`, never `CustomRecord::create(...)`.
- Always import classes; never an inline `\App\…` reference. Imports sorted alphabetically.
- Negation carries no space: `if (! $x)` is wrong here — write `if (!$x)` (Pint: `not_operator_with_successor_space: false`).
- Early returns over nested conditionals. Statements breathe — blank lines between logical groups. Nested calls break across lines. String interpolation over concatenation or `sprintf`. Laravel helpers (`Str::`, `collect()`, `data_get()`) over raw PHP equivalents.
- Curly braces on every control structure, including single-line bodies.
- **Migrations:** strict column order `id` → FKs → unique → boolean → regular → `softDeletes()` → `timestamps()`. Single-column FK indexes are declared **inline without an explicit name**, with `->index()` before `->constrained()`; long fluent chains wrap with `$table` on its own line and one indented line per method. Composite indexes and uniques stay separate statements with an explicit name (Postgres 63-char limit) and include `tenant_id`. `down()` is always reversible.
- **Tests:** Pest. Feature tests for endpoints, unit tests for Actions and Services. Test names are prose. No top-level `function` declarations in a test file — Pest loads every test file into one process and same-named global helpers collide across files; put helpers on `$this` as closures in `beforeEach`. Fixtures under `tests/Fixtures/` are standalone. `->skip()` always carries a named reason as its second argument, and the condition is never a `static fn` (Pest rebinds it, which errors under PHP 8.5).
- **Frontend:** TypeScript strict, no `any` (use `unknown` plus a type guard). `interface` for object shapes, `type` for unions. Const objects `as const` instead of the `enum` keyword. Type-only imports. No `as Type` assertions except after a guard. Tailwind v4 utility classes only — no global custom classes; scoped `<style>` only for CSS variables or third-party overrides. Route helpers come from Wayfinder (`@/actions/`, `@/routes/`); regenerate with `--with-form` when a page uses `.form()`.

#### Patterns & Paradigms

- **Required:** Strategy (interface in `App\Contracts\{Domain}\` + DI) for anything with interchangeable implementations, such as field-type handlers and automation actions. Transactional outbox for cross-domain communication — Laravel `Event`/`Listener` classes are **banned** here, the fan-out runs as Temporal activities off `OutboxRelay`. Factory and Builder where construction is non-trivial. DTOs (typed, immutable) between layers. Value Objects that validate on construction. Registry classes for runtime-extensible sets.
- **Required:** errors are typed. A domain failure is a named exception in `app/Exceptions/{Domain}/`, converted at the controller boundary into a `ValidationException` bound to a rendered field, or into the API's error shape. Never a stringly-typed error, never a bare `\Exception`.
- **Required:** persisted custom-field data uses **JSONB + GIN** on `custom_records.data`. Not EAV, not Data Vault. Multi-tenancy is shared-DB with a tenant column plus partition-by-tenant for large tables.
- **Required:** all async work is a Temporal workflow, activity or schedule. Note the Temporal typing trap — a `WorkflowProxy` does **not** implement the workflow interface; type-hinting it as the interface raises a `TypeError` that a surrounding `catch` will swallow. Address signals by their string name and rely on the project's PHPStan extension.
- **Required:** row-level capability that the UI must respect is exposed from the server as a boolean plus a human-readable reason (`can_delete` + `delete_reason`), so the grid renders the action disabled with a tooltip. A silently hidden action leaves the user with no explanation.
- **Banned:** Repository pattern layered over Eloquent. Fat models and Active Record as a logic carrier. Anemic domain models. Service Locator. Singletons carrying business logic. Inheritance used purely for code reuse.
- **Required:** cross-package integration runs through the **consumer's** extension point, never a direct reach into another package. A package may reference `Nubos\\Other\\*` only if its own `composer.json` declares that package under `require`, or — for an optional integration — under `require-dev` **and** `suggest`. `suggest` alone never licenses a reference. Whoever *offers* a capability ships the adapter and registers it in the consumer's registry (`automation.action_handlers`, `timeline.sources`, tagged `App\\Contracts\\*` interfaces, `module.json` `extensions`/`options`); the consumer never learns the provider's name. The core gains a port only for a capability the core itself offers, so a future package plugs in without a core release. Runtime presence is asked of `App\\Support\\Modules\\InstalledModules` by module key (`nubos/automations`) — **never** `class_exists()`, which stays true in dev and CI and therefore proves nothing. Enforced by `App\\Support\\Conventions\\PackageBoundaryScanner` in `tests/Feature/Schema/PackageBoundaryTest.php`.
- **Banned:** a second way of doing something that already has a way. Before adding an abstraction, a wrapper, a helper class or a configuration switch, check whether an existing one covers it; a helper with a single caller and no second use case is not an abstraction.

#### Tests

> **Binding for every PHP test.** Locked 2026-09-22.

- **No database in a test.** A test declares `uses(TestCase::class, WithoutDatabase::class)` (`Tests\Support\WithoutDatabase`), which points the default connection at a dead host and purges it. Every real query then fails with `QueryException` — so a test that needs one is a wrong test, not an accepted exception. No `RefreshDatabase`, no `DatabaseMigrations`, no `TEST_TOKEN`, no `--parallel`, never two runs at once. `tests/Unit/Architecture/DatabaseFreeTestsTest.php` enforces it.
- **No CRUD test.** That Eloquent writes and reads a row is framework behaviour and is not tested — neither are factories, seeders nor "the column exists". What is tested is behaviour: authorization, scopes, validation, business rules, error paths and which dependency gets called with what.
- **Build models in memory.** `Tests\Support\ModelStub::make($class, $attributes, $relations)` fills raw attributes (never `forceFill` — `CustomRecord::setAttribute` resolves dynamic relations and would query), sets `exists`, wires relations via `setRelation` and derives deterministic ULIDs from a seed (`ModelStub::ulid('company-record')`). No factory, no `save()`.
- **Bind the access context, do not create it.** `Tests\Support\AccessContext` binds `current_tenant`/`current_team`, sets the acting user, and installs the doubles: `grant('companies.view')` (a `PermissionResolver` that answers a fixed ability list and records what was asked), `rowAccessRules([...])`, `recordRuleCompiler()` and `enforceRowAccess()`/`suspendRowAccess()`. `Tests\Support\Doubles\GateSpy::allowing(...)` hooks the real `Gate` through `Gate::before` and records every `authorize()`.
- **Scope test = query shape.** `Tests\Support\QueryShape::of(Model|Builder)` exposes `sql` plus `bindings` and the predicates `isScopedToTenant()`, `blocksEveryRow()`, `isKeyedTo()`, `hidesSoftDeleted()`, `targets()`. `QueryShape::attemptedBy($closure)` returns the query a code path *tried* to send (null when it never reached the database) — that is how a check that re-queries the row, such as `CustomRecordPolicy::isWithinRowAccess()`, is proven.
- **Policy and controller test = authorization before delegation.** Instantiate the controller with mocked Actions, install a `GateSpy`, and assert both the refusal and that the Action was never called. The happy path asserts which keys the Action received, so a mass-assignment leak shows up.
- **Route test = the registration, not a request.** `Tests\Support\RouteShape::named('engine.records.update')` reads the declared middleware (`permission:{objectType}.create`, `can:update,record`), the fully resolved and priority-sorted chain, and `runsBefore(A, B)` for order rules such as `EnforceRecordAccessRules` before `SubstituteBindings`.
- **Middleware interplay = real pipeline, stub terminal.** `Tests\Support\MiddlewarePipeline::forRoute($name, [$middleware, …])` sends a request built by `RouteShape::request()` through the real `Illuminate\Pipeline\Pipeline` with the real middleware classes and a closure terminal. Route model binding is fed by `Route::bind()`, so the effect is observable without HTTP and without a database.
- **Schema test = rendered Postgres DDL.** `Tests\Support\SchemaShape::ofMigration('database/migrations/…php')` runs the migration inside `Connection::pretend()` and returns the real statements; `ofBlueprint()` renders an ad-hoc `Blueprint`. Assert column order (`columnsOf()`), types (`columnDefinition()`), foreign keys (`hasForeignKey()`) and partial unique indexes (`hasPartialUniqueIndex(..., 'deleted_at IS NULL')`), and cover `down()` too. No migration test runs against a server.
- **Postgres specifics = exact SQL plus bindings.** jsonb paths, `ILIKE`, casts, `orderByRaw` and aggregates are asserted through the rendered query against the Postgres grammar — full fragment and binding list, not a loose `toContain('jsonb')`.
- **Never fake the subject.** No `skip()` without a named reason, no empty test, no always-true assertion, and no test that only checks a mock the same test set up. Every new test is first seen red for the right reason: break the guard in the production code, watch the test fail, restore it.

#### UI / UX

> **Binding for every screen.** Read before touching anything under `resources/js/`.
> A screen that deviates is a defect, not a variation. Locked 2026-08-03.

**Page shell**

- Page root is `<div class="flex flex-col gap-6 p-3">`. The title comes from `<Heading variant="small">` with both `title` and `description` — never a hand-rolled `<h1>`. Every page sets `<Head :title>`.
- **Navigation happens in the app header, never inside the page.** `AppSidebarHeader` renders, in this order, the sidebar trigger, a back arrow and the breadcrumb. A page never renders a back link of its own; the heading block starts with `<Heading>`.
- The breadcrumb trail is derived, not declared: `useBreadcrumbTrail()` matches the current URL against the `navigation` shared prop (longest matching leaf, segment-wise) and prefixes the trail with that leaf's group ancestors, which stay unlinked. A sub-page adds only its own tail through `usePageBreadcrumbs(() => [...])` — the entity name, and for a third level the detail page as `{ title, href }` plus the leaf as `{ title }`. Hrefs always come from Wayfinder, never from a hand-written path (a literal drops the `{activeTeam}` prefix).
- A trail with one entry renders nothing — a top-level list carries no breadcrumb and no back arrow. The back arrow points at the nearest preceding entry that has an href, so it goes exactly one level up and behaves like „Abbrechen"; the `useUnsavedChanges` guard catches it like any other visit.
- Content sits in `Card` + `CardHeader`/`CardTitle`/`CardDescription` + `CardContent` — one card per subject block. Secondary actions on a record (rotate, re-check, delete) live in their own card, never in the save row.

**Forms**

- A field is always the triple `Label` (`for`) + control (`id`) + `InputError`. A field without a bound error message is incomplete.
- Field values bind to a `ref` via `v-model`. Never `:default-value` — the Inertia `<Form>` `isDirty` reads native DOM fields only, so a `:default-value` field or any `Select`/`MultiSelect`/canvas state is invisible to dirty tracking.
- Every create/edit form wires `useUnsavedChanges` + `<FormActions>` + `<UnsavedChangesDialog>`: save stays disabled until dirty, cancel returns to the list, and leaving with pending changes asks first. Confirm the save with `markSaved()` via `:on-success` / `onSuccess`.
- The action row is `<FormActions>` and nothing else: **right-aligned**, cancel (`outline`) first, save (primary) outermost right — the same order as `DialogFooter`. No icon-only submit, no second save button.
- The submit is always the plain save button labelled **„Speichern"** — in create mode as well as edit mode. Never `CreateButton` (the plus/add button) as a submit, and never a per-form label like „Token erstellen" or „Create object type". `CreateButton` is exclusively the create action in a list header; a form that submits through it is a defect. `FormActions` therefore carries neither a `mode` nor a `saveLabel` prop — the label is not configurable.
- A user-actionable failure arrives as `ValidationException::withMessages()` on a field the form renders. Never a bare 500, never only a toast.

**Controls**

- Controls come from `@/components/ui/*`. Never a native `<select>`, never a hand-built dropdown, never a raw `<button>` where `ui/button` fits.
- Multi-value selection is `MultiSelect`. Never a list of checkboxes.
- Single selection with a searchable list is `Combobox`; a short, fixed list stays `Select`. The dividing line is the nature of the list, not its momentary length: entity lists (people, teams, records, roles a tenant defines) grow and therefore search; closed enumerations (salutation, scope, operator, combinator, field type) do not. Where the list is data-driven and its size is unknown, pass `:searchable="options.length > 8"`.
- `MultiSelect` always searches — it carries no `searchable="false"` in application code.
- **Never a free-text field for an identifier.** A ULID, a comma-separated id list, or „enter the user key" is a defect, not a stopgap. Every person, team, or record reference is picked from `Combobox`/`MultiSelect`.
- A person option carries `avatar: { name }` and `description` (the e-mail) from the server; `Combobox`/`MultiSelect` then render `UserAvatar` in the list **and** in the trigger or token. The server builds these through `UserOptionPresenter` — never assembled per controller. Options that are not people simply omit `avatar`.
- Option payloads are `SelectOption` (`@/types/ui`) everywhere — one shape for `Combobox`, `MultiSelect`, and field options. Never a local `interface Option`.
- Boolean input is `ui/checkbox`. A raw `<input type="checkbox">` is allowed only when the value must travel in native FormData — then always paired with a `<input type="hidden" value="0">`.
- A standalone **setting** that switches a capability on or off — a policy row, a panel the user shows or hides — is `ui/switch`, with the `Label` left and the switch directly beside it in a `flex items-center gap-3` row — never `justify-between` across a card, which tears label and switch a screen width apart. Only a narrow panel (sheet, popover) may push the switch to the right edge. `ui/checkbox` stays for a boolean that is one field among others inside a form. Added 2026-08-26, updated 2026-09-17.
- Button intent comes from the shared variants (`default`, `outline`, `destructive`, `success`). Never a colour utility on a button.

**Lists**

- Every table is `DataGrid` (AG Grid) or `SimpleTable` (`components/data-grid/`). No hand-built `<table>` and no `AgGridVue` anywhere else — `resources/js/tests/designTokens.spec.ts` guards it. Both take their density from `lib/tableAppearance.ts`; the shared styling lives in `resources/css/table.css` and reaches AG Grid through `lib/agGridTheme.ts`. Links inside cells are `TableLink`; administration lists sit in `ListPage`. Updated 2026-09-08.
- Columns use the column types from `lib/tableColumns.ts` (`number`, `emphasis`, `danger`, `control`, `selection`, `actions`). A page defines columns, data and domain actions — never `cellClass`, `cellStyle` or `headerClass`. Data columns are sortable, filterable and resizable by default; a column excluded on domain grounds keeps its explicit settings. Row and header heights are set explicitly on AG Grid, so a grid hidden behind a Kanban view never falls back to AG Grid's defaults.
- Row actions are icon buttons in the trailing column, right-aligned, in fixed order: edit (pencil) → special actions → delete (red, outermost). A forbidden action renders **disabled with a tooltip naming the reason**, never hidden — the server supplies `can_<action>` + `<action>_reason`.
- **A click anywhere on the row opens it** — the same target the pencil has. Every list wires `DataGrid`'s `:is-row-activatable` (the permission gate, usually `row.can_update`) plus `@row-activate`; the grid ignores clicks on the selection checkbox, the action column and any link or button inside a cell, and marks activatable rows with `cursor-pointer`. The pencil stays as the explicit affordance. The only exception is a grid whose cells are edited inline (`RecordGrid`, `ObjectTypeFieldGrid`) — there the click belongs to the cell editor.
- "Create" sits top-right in the page header as `<CreateButton>`. Deleting always routes through `ConfirmDialog` with `variant="destructive"`.
- Every list whose rows can be deleted **offers multi-select and bulk delete** — this is required, not optional. It is built from the three shared pieces and nothing else: `useListSelection()`, `selectionColumn()` as the leading column, and `<SelectionBulkBar>` above the grid. Prune the selection whenever the rows change.
- A row the server has blocked from deletion is **not selectable** — pass the guard to `selectionColumn(selection, isSelectable)`. A blocked row inside a batch would fail the whole request.
- Empty state hides the grid, its header row and the filter bar — leaving only an explanatory sentence and the create button.

**Feedback and state**

- Success and failure surface as a toast via `ToastType` and the Sonner defaults. No `alert()`, no bespoke toast markup.
- Submit buttons are `disabled` while `processing`; deferred props render a pulsing skeleton, never an empty area.
- Status values render as `Badge` resolved through `@/lib/statusMaps`. Never a hard-coded label or colour.
- A read-only state (system object type, trashed record) shows an explanatory block **and** disables the controls.

**Layout and density**

- Hold the compact scale: controls `h-8` (`h-7` small), page padding `p-3`, card `py-4`, grid header `h-12`. No local size overrides.
- Field grid is `grid gap-4 sm:grid-cols-2`; a lone narrow field gets `sm:max-w-sm`.
- Only design tokens — see **Design system (Atlassian foundations)** below. No fixed colour values, no Tailwind default palette; dark mode follows from the token.

**Design system (Atlassian foundations)**

> The visual foundation is the Atlassian Design System. `resources/css/tokens.css`
> carries the palette (`--ds-palette-*`) and every semantic token for both themes,
> `resources/css/utilities.css` exposes them as Tailwind utilities. Both files are
> generated from `@atlaskit/tokens` — never hand-edit them. `resources/css/app.css`
> is the only mapping layer (shadcn aliases, radius, spacing, shadows). Locked 2026-09-03.

- **Every colour comes from a token.** No hex, `rgb()`, `hsl()` or `oklch()` literal and no Tailwind default-palette utility (`bg-red-500`, `text-gray-400`) anywhere under `resources/js/`. `resources/js/tests/designTokens.spec.ts` guards both; a new offender is a failing test, not a style opinion.
- **No `dark:` variant on a colour.** Light and dark are two values of the same token, so a component names the token once. A `dark:bg-*`, `dark:text-*` or `dark:border-*` is a defect.
- **Semantic before accent.** Colour that carries meaning uses an intent — `danger`, `warning`, `success`, `information`, `discovery`. Colour that only distinguishes or decorates (avatar tones, automation node kinds, tags) uses an accent. An accent never means anything, and an intent is never used for decoration.
- **Emphasis ladder per intent:** background `bg-{intent}` (subtlest) → `bg-{intent}-subtler` → `bg-{intent}-subtle` → `bg-{intent}-bold`; text `text-{intent}` / `text-{intent}-bolder`; icon `icon-{intent}`; border `border-{intent}` / `border-{intent}-subtle`.
- On a `-bold` background the foreground is `text-inverse` — **except warning**, which pairs with `text-warning-inverse` (dark text on yellow).
- **States use the state token,** never an opacity modifier: `hover:bg-danger-bold-hovered`, not `hover:bg-danger-bold/90`.
- **Accents:** ten hues (blue, teal, green, lime, purple, magenta, red, orange, gray) — **yellow is not used**, it reads brown and fails contrast. Background, text, icon and border of one element always come from the same hue; never mix families. Pair `text-accent-{hue}` or `-bolder` with the `-subtlest`/`-subtler` backgrounds, `-bolder` only with `-subtle`, and `text-inverse` with `-bolder`.
- **Components stay shadcn-vue on Reka UI, icons stay Lucide.** No Atlaskit React components, AppProvider, XCSS or Atlassian layout primitives; no `@atlaskit/*` UI import (guarded by `designTokens.spec.ts`). `@atlaskit/pragmatic-drag-and-drop` is the one allowed Atlaskit package — it is drag-and-drop behaviour, not UI. Instrument Sans stays the typeface.
- **Surfaces:** page `bg-background`, inset area `bg-muted` (sunken, only on the base surface), card `bg-card` (raised) and menu/select list `bg-popover` (overlay) with a uniform 1 px grey border and **no shadow**; dialog and sheet `bg-popover` with `shadow-overlay`. Shadows come from `shadow-raised` and `shadow-overlay` only. In dark mode higher surfaces get lighter — surface and shadow always switch together. Updated 2026-09-08.
- **Product chrome:** white sidebar, light-blue selected navigation items and view toggles, blue primary actions. Kanban columns and table headers sit on neutral grey surfaces; table hover and selection use the ADS semantic tokens directly.
- **Type scale (compact ADS adaptation, rem-based):** `text-xs` 12/16 px secondary metadata · `text-sm` 13/18 px standard UI, labels, buttons (global base) · `text-base` 14/20 px running text · `text-lg font-bold` 14/20 px small section titles · `text-xl font-bold` 18/24 px dialog titles · `text-2xl font-bold` 22/28 px page titles · `text-3xl font-bold` 24/28 px · `text-4xl font-bold` 32/36 px. Weights: body 400, controls 500, titles 700. Compact tables stay at 12 px and use tabular figures. HTML heading levels follow the content hierarchy, not the visual size.
- **Content grid:** `content-grid` is the container for content layouts inside the app shell — 2 columns / 12 px gap / 16 px margin below 480 px, 6 / 12 / 16 px from 480 to 1023 px, 12 / 16 / 32 px from 1024 px; max width 1296 px including margins. `content-grid-narrow` caps at 864 px, `content-grid-fluid` releases the full width; full-width sections use `col-span-full`. Single buttons and fields need not align to the page grid, and existing grids and boards are not forced into it.
- **Borders:** 1 px regular, 2 px for selection and focus (focus colour only — see Focus).
- **Motion** explains a state change and never blocks work: 100 ms for interactions (`motion-interaction`), 150/100 ms in/out for small overlays, 250/200 ms for dialogs, with different easing for enter and exit. Focus and error messages appear without delay. `prefers-reduced-motion` reduces animation and transitions globally.
- **Icons and brand:** one icon set (Lucide) with consistent meaning and size; an icon-only action carries an accessible name. The Nubos brand stays — Atlassian logos are never used as branding. Illustrations may support empty states and onboarding but never replace an understandable sentence.
- **Feedback mapping:** success → Sonner toast confirming the result; error → field message or `Alert` naming the problem and the next step; warning → `Alert`/dialog explaining the consequence before the action; information → `Alert` or hint text; empty state → the shared empty state with reason and next action; new feature → the existing introduction/dialog. No Atlaskit flag, banner or spotlight library.
- **Accessibility floor:** contrast at least 4.5:1 for normal text and 3:1 for large text and essential graphical controls; keyboard use, focus handling and dialog semantics stay with Reka/shadcn; zoom, narrow viewports and reduced motion are part of every check. The contrast specs cover the central text/surface pairs in both themes — they do not replace a full accessibility audit.
- **Data visualization:** series colours are `--chart-1 … --chart-8` (Atlassian categorical 1–8) **in that order** — never reordered, never hand-picked. A chart shows at most five categories plus a grey „Sonstige" on `--chart-neutral`. Status and severity charts use the chart intent tokens instead of the categorical sequence. Colour is never the only carrier of meaning — a label, shape or legend entry carries it too — adjacent areas are separated by a gap or border, and text is placed beside a chart element, never on top of it.
- **Shape and space:** radius from `rounded-xs|sm|md|lg|xl|2xl` (2/4/6/8/12/16 px); spacing on the 4 px scale (`--ds-space-*`). No arbitrary radius or spacing value.
- **Focus**: a form field never shows a ring. Every field (`Input`, `Textarea`, `SelectTrigger`, `Combobox`, `MultiSelect`, `Checkbox`) carries a 1 px `border-input` border at rest and a 1 px `border-bold` border while focused — no colour change, no second pixel (locked 2026-09-06, guarded by `resources/js/tests/fieldFocus.spec.ts`). Everything else — buttons, links, menu items, cards — keeps the global 2 px `--ds-border-focused` outline from `app.css`; there it is never removed and never recoloured.
- **Toasts** carry the intent surface: `background.{intent}` with `border.{intent}` and the intent icon, default text on top. The neutral toast stays on the overlay surface. The values reach Sonner through its own `--{type}-bg/-border/-text` variables, set once on `Toaster` — never through a per-toast class.
- **Deliberate deviations from Atlassian**, all locked: the compact type scale of the „Dicht" density decision stays instead of the 14 px Atlassian body scale; Atlassian's font stack and `653` bold weight are not adopted because Atlassian Sans is not shipped; and the global base size is 13 px instead of 14 px on explicit user request. The create button (`create` variant) stays green on the Lime400 `success-solid` tokens, identical in both themes, so creating stands apart from the blue primary actions (user decision, re-confirmed 2026-09-17). The `success` button variant, the success lozenge, success icons and the success toast stay on the Atlassian success tokens.

**Navigation**

- Menu entries come exclusively from `NavigationBuilder` (backend, permission-filtered). Never hard-code a nav entry in the frontend.
- Targets resolve through Wayfinder (`Controller.index.url()`), never a string URL, never `window.location`.

**Language**

- User-visible copy is **German**, including labels, buttons, headings, card descriptions, hints, empty states and dialog text. Identifiers, props, events, `data-*` hooks and test names stay English.
- The German copy addresses the user **formally („Sie")**, never informally („Du"). This covers hints, empty states, toasts, dialogs and error messages alike — „Bitte versuchen Sie es erneut.", never „Bitte versuche es erneut." Imperatives without a pronoun („Speichern", „Abbrechen") stay as they are; a sentence that needs a pronoun uses Sie/Ihr.
- Shared label maps and component defaults are German too, because they leak into every screen that does not pass an explicit label: filter operators, permission labels, `DeleteButton`, `ConfirmDialog`, `SelectField`, formula errors, grid headers. An English default in a shared component is a defect, not a placeholder.
- Server-side messages that reach the user are **German** and formal, exactly like the screen: validation messages, the message of an exception that is rendered into a page or into an API `detail`, flash and toast copy a controller hands to Inertia, notification and mail bodies, and the reason text of a refusal a user is shown. Never patch such a message in a Vue template — repair it where it is produced.
- Everything a user never reads stays **English**: identifiers, class, method, property and route names, `Log::` messages together with their context keys, exception messages that are only caught and mapped by their own caller, database columns, config and translation keys, and test descriptions. The dividing line is one question — **can this string reach a screen or an API error payload?** If yes it is German, if no it is English. Follow the string when in doubt: an exception that `bootstrap/app.php` or a handler turns into a response is user-facing; one whose only caller catches it and produces its own message is not. A message that is both logged and shown gets a German user text and an English log line, never one string doing both.
- Framework copy is translated in `lang/de/`, never inline. `lang/de/validation.php` holds Laravel's own rule messages plus an `attributes` map that turns a column name into the field title the form shows; `auth.php`, `passwords.php` and `pagination.php` hold the rest. `APP_LOCALE` is `de` — `APP_FALLBACK_LOCALE` stays `en` because the same setting doubles as the storage locale of translatable content (`i18n_labels` and translatable field values are keyed by it), so changing it would orphan stored rows. A per-validator `messages()` override that only restates a line `lang/de/` already carries is duplication and gets deleted; override only where the rule needs wording the generic line cannot give.
- PHP may carry German only for that user-facing copy: enum `label()` values, navigation labels, toast and flash messages, option labels a controller hands to a page, export headers and notes, and the messages above. A German literal in a log line, a cache key or an internal-only exception is a defect in the other direction.
- Copy that is **persisted** is content, not a label, and never carries a hardcoded language literal. Anything appended to a stored name or description (the duplicate suffix, a generated title) comes from `config/`, so a non-German deployment can change it without touching code.

**Tests**

- Every UI change ships a Vitest spec. Stub selects with `@/tests/selectStubs` (`selectStubs`, `comboboxStubs`, `multiSelectStubs`); a spec that mounts `Combobox`/`MultiSelect` itself stubs the reka-ui primitives with `@/tests/comboboxPrimitiveStubs`. Assert against `data-*` hooks, never CSS classes.

### Out-of-Scope (Forever)

- **No concrete business domain in the platform core.** Deal, Projekt, Angebot, Kontakt and every other named business object belong to apps built on the platform, never to the platform. The core stays generic: runtime-defined object types, the custom-field engine and its handlers, relations and links, configurable lists and state, audit, RBAC and visibility, automations, templates, API and webhooks.
- **No CRM.** Pipedrive is technical orientation for patterns only; this is not a CRM and not a Pipedrive clone.
- **No Laravel Queue as an execution path.** Not as a fallback, not for "just this one job".
- **No Data Vault in the transactional core.** It belongs, if ever, to a separate analytics or warehouse layer.
- **No UUID primary keys.** ULID, permanently (D5.1).
- No benchmark, demo or test that proves platform behaviour through purpose-named tables instead of the generic `custom_records` engine.
