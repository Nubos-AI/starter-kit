---
name: nubos-coding-standards
description: How code is written in the Nubos platform. Use for EVERY coding task in this repo — implementing features, creating models/endpoints, fixing bugs, writing tests, or reviewing code (Laravel, Vue, React/Capacitor, C#). Contains the non-negotiable core rules inline and routes to the detailed convention files, templates, learnings, and UX patterns in knowledge/.
---

# Nubos Coding Standards

Single entry point for how code is written here. The full knowledge library lives in `knowledge/` — this skill inlines the rules that apply to *every* task and tells you which detail file to read for the rest. The screenshots in `knowledge/laravel-knowledge/` are transcribed in Section 7, so you never need to open the images.

**How to use this skill:**
1. Apply Section 1–6 to every task, always.
2. Before touching a specific artifact type (model, migration, component, …), read its file from the routing table in Section 9.
3. Before writing anything new, check `knowledge/knowledge-base/seed-learnings/` (Section 8 summarizes them) and use the matching template from `knowledge/knowledge-base/templates/` — never start from scratch.
4. Before handing off, run the Definition of Done (Section 10).

---

## 1. Working Method (the #1 source of quality issues)

**Read before write — mandatory.** Before adding or modifying ANY method in an existing class:
1. Read the ENTIRE class — every property, method, trait, parent class.
2. Check if the functionality already exists (class, trait, parent). If yes, use it — never create a second way.
3. Identify the patterns the class uses. New code MUST follow the same patterns — no mixing (if the class uses DTOs, you use DTOs; if all accessors use `Attribute::make()`, yours does too).
4. Never redeclare what a trait or parent provides (e.g. `HasUlids` already sets `$keyType` and `$incrementing`).

**No duplicate code — no exceptions.**
- Before writing new code, search the domain for overlapping logic (existing Actions, Services, Traits).
- 3-line rule: >3 lines of identical/near-identical logic in two places → extract immediately. "Refactor later" is forbidden.
- Extraction decision:

| Standalone operation? | Used in multiple classes? | Extract to |
|---|---|---|
| Yes | irrelevant | Own class (Action/Service) |
| No | Yes | Trait in `Traits/{Domain}/` |
| No | No | Private method |

- Abstract base class only for a shared *sequence of steps* (Template Method) with ≥3 concrete implementations — never speculatively.

**After every task:** remove dead code, unused imports, empty attribute declarations (`#[Hidden([])]`, empty `casts()`), functional duplicates, and pattern inconsistencies.

## 2. Architecture

Strict layering, dependency direction top-down only:

```
Controller/Handler  → HTTP concerns only (request in, response out)
  Validation        → input validation + authorization
    Action/Command  → business logic, transactions, events  ← THE home of business logic
      Service       → infrastructure only (external APIs, storage, mail)
      Model/Entity  → data: relationships, scopes, accessors — NO business logic
```

- Forbidden: Models calling Actions; Controllers calling Services directly; Services calling Actions.
- Group code by business domain (`Actions/Projects/`, `Events/Billing/`), not by technical type.
- Within a domain: Actions may call Actions. Cross-domain: write to the transactional outbox (`OutboxEvent`, drained by `OutboxRelay` into Temporal activities) — never call another domain's Actions, and never add a Laravel `Event`/`Listener` class.
- Placement: Interfaces → `App\Contracts\{Domain}\` (never inside Services/). Abstract classes → `{Parent}\Abstracts\`. Traits → `App\Traits\{Domain}\`.
- Patterns we use: Strategy (swappable providers via interface + DI), transactional outbox for cross-domain fan-out, Factory, Builder, DTO, Value Object, Abstract base.
- Patterns we do NOT use: Repository over Eloquent, fat models (Active Record as logic carrier), Service Locator, Singletons for business logic.
- SOLID applies everywhere; max 2 levels of nesting per method — use early returns, guard clauses, polymorphism, lookup tables.
- Multi-tenancy: tenant isolation is automatic at the data layer (global scope), never manual per-query filtering.

## 3. Laravel Backend

### Class basics
- Every file: `<?php` → `declare(strict_types=1);` → namespace → alphabetical imports → class. No inline `\App\...` qualifiers — always import.
- Member order: traits → properties → constructor → methods; within each group `public` → `protected` → `private` (per `class-anatomy.md`; see Section 11, conflict K4).
- Constructor injection with promotion for all dependencies — never `app()->make()` / `resolve()` in application code. No empty constructors. Explicit return types on every method.

### Actions (business logic)
- One Action = one business operation. Entry method `execute()` (or the interface method if one exists). Naming `{Verb}{Resource}Action`, namespace `App\Actions\{Domain}\`.
- Actions own validation: `Validator::make($data, $rules)->validate()`. Controller passes `$request->all()`. Enum fields validated with `Illuminate\Validation\Rules\Enum`.
- ALL model CRUD (`::query()->create/update/delete`) happens inside Actions — never in Controllers, Services, or Commands.
- `DB::transaction()` in Actions for multi-write operations — never in Controllers. Dispatch events after state changes.

### Controllers
- Thin. No business logic, no inline `validate()`. Authorization is ALWAYS the FIRST call: `$this->authorize('ability', $model)`.
- Single-action controllers with `__invoke()` for non-CRUD; resource controllers only for full CRUD. Naming: `{Resources}Controller` (plural) / `{Resource}{Action}Controller`.
- API → `JsonResponse` via Resources; Web → `Inertia::render()`. Route model binding via type-hint. Route-specific middleware via `#[Middleware('...')]` attribute on the class/method.
- FormRequests exist ONLY when no Action handles the endpoint (non-CRUD like exports, bulk ops). Naming `{Verb}{Entity}Request`.

### Models
- `HasUlids` (never set `$keyType`/`$incrementing`), `SoftDeletes`, `HasFactory` — always. Primary keys are ULID everywhere; `HasUuids` is forbidden (CLAUDE.md › Project Rules, locked decision D5.1).
- `#[Fillable([...])]` explicit whitelist — `#[Guarded([])]` is forbidden. Field order matches migration column order. Never fillable: `is_admin`, `role`; programmatic fields like `tenant_id` are set by the Action.
- `casts()` **method** (not `$casts` property); omit entirely when empty. Enum columns cast to their Enum class.
- `#[Hidden([...])]` for sensitive fields; `#[Appends([...])]` only for accessors that must appear in JSON — never declare empty attributes.
- Accessors: `Attribute::make(get: fn () => ...)` — PHP 8.4 property hooks do NOT work on Eloquent Models (fine on Actions/Services/DTOs/VOs).
- Local scopes: `#[Scope]` attribute on protected method. Global scopes: `#[ScopedBy([TenantScope::class])]` on the class — never `addGlobalScope()` in `booted()`.
- Relationships as typed methods only — singular for BelongsTo/HasOne, plural for HasMany/BelongsToMany. Relationship needed on nearly every query → `protected $with = [...]` on the model; endpoint-specific eager loading stays in the Controller.
- `#[UsePolicy(XPolicy::class)]` on every API-exposed model. Naming: singular PascalCase class, plural snake_case table.
- **`::query()` is mandatory** on every Eloquent call that hits the DB: `Project::query()->create(...)`, never `Project::create(...)`.

### Migrations
- Column order (strict): `id` → foreign keys → unique fields → boolean/settings → regular fields → `softDeletes()` → `timestamps()`.
- `$table->ulid('id')->primary()`; FKs to ULIDs via `foreignUlid()->constrained()->cascadeOnDelete()` (both sides ULID — type mismatch breaks the constraint). Never `uuid()`/`foreignUuid()` (D5.1).
- Index naming `idx_{table}_{columns}`; composite indexes as separate statements. Enum columns: `string(20)->default(StatusEnum::Case->value)` — never native DB enums. Polymorphic: `ulidMorphs('model')`.
- Changes to existing tables = new migration file. No data manipulation in migrations (→ Seeders). Boolean columns `is_/has_/can_/should_`, timestamps `_at`.
- Every migration ships a reversible `down()` (this project's ruling; see Section 11, conflict K1).

### Everything else
- **Enums**: backed string enums, PascalCase cases, snake_case values, `App\Enums\`, no business logic (labels/colors only). No `const` anywhere (CLAUDE.md › Project Rules): value sets become Enums, tunables become properties or `config/` entries.
- **Outbox entries**: written inside the Action's transaction via `AuditRecorder`, carrying tenant, object type, record, version and changed field keys — there is no `app/Events/` in this project.
- **Activities**: present tense (`RelayOutboxActivity`), one side effect each, in `app/Activities/{Domain}/` behind a `…ActivityInterface`, invoked from a Temporal workflow — never `ShouldQueue`, never a Laravel listener.
- **Services**: infrastructure only (`StripePaymentService`), `readonly class` when stateless, interface in `App\Contracts\{Domain}\` when swappable, registered in ServiceProvider (singleton/scoped). Never call Actions from Services.
- **Commands**: signature `{domain}:{verb}`, class `{Verb}{Entity}Command`, parse input + delegate only, return `Command::SUCCESS/FAILURE`.
- **DTOs**: `readonly class`, constructor promotion, `Create{Model}Data`/`{Model}FilterData`, `App\DTOs\{Domain}\`, static factories `fromArray()/fromRequest()`, no validation/business logic inside. Prefer DTOs over `array $data` for complex signatures.
- **Value Objects**: `App\ValueObjects\`, immutable, self-validating constructor, equality by value, may have behavior (`Money::add()`). Money = integer cents + currency — NEVER float. Bind to Eloquent via custom Casts.
- **Policies**: `{Model}Policy`, `App\Policies\{Domain}\`, default-deny, verify tenant membership, called from Controllers only.
- **Routing**: kebab-case plural URLs, `apiResource`, `->shallow()`, max 2 nesting levels, names `{resource}.{action}`, `auth:sanctum` + tenant context middleware via groups, no logic in route files.
- **API Resources**: every response goes through a Resource — never raw Models/`toArray()`. JSON keys camelCase, timestamps `->toISOString()`, ULIDs as strings, enums as `->value`, relationships only via `whenLoaded()` (never load inside a Resource), nesting ≤ 2 levels, no business logic. Frontend TS interfaces mirror the Resource shape.
- **Factories**: every model has one; enum fields via `fake()->randomElement(Enum::cases())`; states per variant; lazy relationship resolution (`Tenant::factory()`).
- **Seeders**: dev/test only, called from `DatabaseSeeder`.
- **Config**: `env()` ONLY in config files — application code uses `config()`. Service credentials in `config/services.php`. Config filenames kebab-case, keys snake_case, no real credentials as defaults.

### Code style (Pint + these rules)
- No explanatory comments. Only type docblocks: `@param`, `@return`, `@throws`, inline `/** @var */`, generics. Blank line after last `@param`; never `@return void`; no docblock when there's nothing to document.
- Negation without space: `if (!$user->isAdmin())`.
- Nested calls multiline — never inline. String interpolation over `sprintf`/concatenation. Short ternary inline, complex ternary multiline.
- Laravel helpers over raw PHP (`Str::`, `collect()`, `data_get()`).
- Statements breathe (blank lines between them; grouped one-liners may stack); never blank lines directly inside braces.

## 4. Frontend

### Vue 3 (Inertia backoffice)
- Always `<script setup lang="ts">` — never Options API. Props via `defineProps<Props>()` generics, emits via named-tuple `defineEmits<{ 'machine-updated': [machine: Machine] }>()`, models via `defineModel()` (default binding until ≥2 values, then named), template refs via `useTemplateRef` (element available only after mount).
- Naming: components PascalCase multi-word (file + usage in templates); props camelCase in script / kebab-case in parent template; events kebab-case verb phrases; composables `use{Feature}` (file = function name, return a plain object); Pinia stores `{name}Store.ts` **without** `use` prefix; pages mirror routes (`Pages/Projects/Index.vue`).
- Styling: Tailwind utilities only; shadcn/ui as primitives; scoped `<style>` only for CSS variables/third-party overrides. Template max ~100 lines → extract.
- No direct API calls in components — Inertia `router`/`useForm()` for mutations, props/`usePage()` for reads. Navigation via `router.visit()`/`<Link>` — never `window.location`. Partial reloads via `router.reload({ only: [...] })`. No async data fetching in pages.
- Composables: top-level calls only, one concern each, explicit return types, expose the minimum. Global state → Pinia store, never module-level refs in a composable. Complex UI logic → prefer Pinia (DevTools DX).
- TypeScript: `strict: true`, no `any` (use `unknown` + type guards), `interface` for object shapes, `type` for unions, enums as `const` objects `as const` (never TS `enum`), no `as Type` assertions except after guards, types in `resources/js/Types/{Domain}.ts` mirroring API Resources.
- Security: no `v-html` with user input (DOMPurify if unavoidable), no secrets/`VITE_` secrets in frontend (server proxies external APIs), tokens in httpOnly cookies — never localStorage, client-side guards are UX only, CSP-safe code (no eval/runtime compiler).
- Testing: Vitest (browser mode + Playwright for components, `vitest-browser-vue` for rendering). Prefer component *integration* tests over unit tests (render the wrapper, cover composition, check coverage). Visual regression screenshots for key states incl. empty/loading/error. Pure composables → node; Pinia → `createTestingPinia` inside component tests.

### React + Capacitor (mobile field app)
- Functional components only, no `React.FC` — named `Props` interface + destructuring in the signature. Pages default-export, reusable components named-export. JSX max ~80 lines. Handlers `handle*`, callback props `on*`. Keys = stable IDs, never index. `{count && ...}` forbidden — use `count > 0 &&`.
- Data fetching: TanStack Query (`useQuery`/`useMutation`) — never `useEffect` + fetch. All HTTP through `src/lib/api.ts`. Server state in Query, local state `useState`; minimize `useEffect` (prefer event handlers); exhaustive deps; always cleanup subscriptions/timers.
- Capacitor: plugins wrapped in custom hooks — components never import `@capacitor/*` directly. `Capacitor.isNativePlatform()` (no UA sniffing), web fallback for every native feature, safe-area insets always, keyboard listeners adjust layout, splash dismissed programmatically.
- **Permissions before every hardware plugin call** (`checkPermissions()` → `requestPermissions()` → call), graceful denial handling — skipping this silently fails on iOS and crashes Android.
- Mobile security: auth tokens in `SecureStoragePlugin` (Keychain/Keystore) — NOT `Preferences`/localStorage (ruling per react/security.md; see Section 11, conflict K2). `Preferences` only for non-sensitive settings. Deep links validated against host + path whitelist. Certificate pinning for production APIs. Offline cache encrypted; clear secure storage on logout. `href` validated against `javascript:` injection; no `dangerouslySetInnerHTML` with user input.

## 5. API, Errors, Logging, Caching, Database

### API design
- Plural kebab-case resources, `/api/v1/`, max 2 nesting levels, custom actions as sub-resource (`POST /projects/{id}/archive`).
- Every response in a `data` envelope; collections always paginated (default 25, max 100) with meta+links; dedicated serializers only.
- Filtering `?filter[status]=active` (whitelisted), sorting `?sort=-created_at` (documented default — unsorted lists are non-deterministic), includes `?include=team` (whitelisted, depth ≤ 2).
- Status codes: 422 validation, 401 unauthenticated, 403 forbidden, 404 not found, 429 rate-limited, 500 unexpected. Idempotency keys for critical POSTs (payments). OpenAPI 3.1 spec maintained + CI-validated.
- Error envelope: consistent, produced by the ExceptionHandler — Laravel shape `{"type", "message", "errors"}` (see Section 11, conflict K3 re: the general `error.code` shape).

### Error handling
- Specific exception subclasses only (never generic base), in `Exceptions/{Domain}/`, carrying structured context. Forbidden: empty catch blocks, broad catch without log/rethrow, debug output (`dd`, `dump`, `console.log`) in committed code.
- Frontend: global error handler + error boundaries; 422 → field-level form errors; network errors get retry/fallback UI.

### Logging
- Structured key-value context — NEVER interpolate variables into messages. Correct levels (`info` = significant business event). Correlation ID (`request_id`/`job_id`) on every entry, propagated via `X-Request-Id` on outbound calls.
- Log: auth events, business-critical writes, external API calls, job failures, scheduled runs. Never log: passwords, tokens, PII values. DSGVO mutations → append-only audit trail (entity, actor, action, old/new values, timestamp).

### Caching (Redis only)
- Key pattern `{tenant}:{feature}:{entity}:{id}:{variant}` — tenant data ALWAYS prefixed with the tenant id (global scope does not cover the cache!). Every entry has a TTL (+ ±10% jitter). Tag-based invalidation, executed in the Action/Service that mutates — never in controllers; cross-domain via Events.
- TTLs: permissions 5m, config/flags 15m, KPIs 1h, static reference 24h. Request-scoped repeats → `once()`/scoped singleton. Never cache serialized JSON responses; never share entries across tenants; handle Redis downtime by falling through to source.

### Database (PostgreSQL only)
- Every WHERE/ORDER BY/JOIN/HAVING column indexed. Composite order: equality columns first, then range/sort. Partial indexes for filtered queries (`WHERE deleted_at IS NULL`). Unique constraints at DB level. GIN for JSONB/full-text. `CREATE INDEX CONCURRENTLY`.
- No `SELECT *`; `EXISTS` over `COUNT(*) > 0`; window functions over self-joins; keyset/cursor pagination instead of deep OFFSET; transactions short — locks late, release early; never hold a lock during an external API call; consistent lock order (parent → child, PK ASC); advisory locks for app-level locking; retry deadlocks ≤3× with jitter.
- Bulk: multi-row INSERT (1k–5k/batch) or `COPY`; single-statement conditional UPDATEs; chunked deletes (see 7.4). Read replicas for list/report/export queries — never read-after-write from the replica.
- Zero-downtime migrations: additive changes safe; drop/rename/type-change via multi-step (add new → backfill → switch code → drop old); NOT NULL via `CHECK ... NOT VALID` first; >30s migrations become background jobs.
- JSONB for unstructured attributes only — never for relational data that should be an FK.

## 6. Security (all stacks)

- Server-side validation is the only boundary — client validation is UX. Every input passes a validation layer before business logic.
- SQL: parameterized bindings only; raw expressions (`whereRaw` etc.) only with `?` bindings.
- AuthZ: Policy on every controller action, default-deny, tenant scoping automatic (global scope), Gates for non-model abilities. AuthN: rate limit logins (5/min/IP), session regeneration after login, MFA for elevated ops.
- Tenant-scoped FK validation: `Rule::exists('clients', 'id')->where('tenant_id', $tenant->id)`.
- Uploads: validate MIME **and** extension **and** dimensions (`Rule::dimensions()->maxWidth(4000)->maxHeight(4000)` — see 7.8), size cap, private disk, random filenames, no executables, serve via signed URLs/authorized controller.
- Rate limits: auth 5/min/IP, API 60/min/user, uploads 10/min/user, webhooks 30/min/source — with standard headers.
- Sanctum: explicit token abilities (never `['*']` in production), explicit expiration, stateful domains without wildcards. Session cookies `secure` + `httpOnly` + `sameSite=lax`.
- Secrets: never in code/Git; `.env.example` with keys only; access via config layer; vault for production; rotatable without deploy (90-day schedule, overlap period); least privilege; never in logs/errors/responses.
- Dependency audits blocking in CI: `composer audit` (any advisory), `npm audit --audit-level=high`. Security headers via middleware (CSP, HSTS, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy). Security events logged with correlation IDs (login failures, permission denials, rate-limit hits).
- DSGVO: soft deletes for retention, hard delete via scheduled process after the retention period, PII fields documented + encrypted at rest, audit trail for PII mutations.
- OWASP Top-10 stack mapping: `knowledge/knowledge-base/conventions/general/security-advanced.md`.

## 7. Performance & Data Playbook (laravel-knowledge, images transcribed)

Source: `knowledge/laravel-knowledge/` — each topic is a text post + image carousel. This section IS the content of those images; do not re-read the PNGs.

### 7.1 Eloquent performance (eloquent/)
The DB is rarely the bottleneck — the plan is. Costs: N+1 queries, model hydration, memory. Debug order: **measure first** — (1) count queries (Telescope/Debugbar) → fix N+1, (2) `EXPLAIN` the slow one → add the right index, (3) check hydration → `DB::table()`/`toBase()` or select only needed columns, (4) >10k rows → `chunkById(1000)` or `lazy()`.
- Prevent N+1 structurally: `Model::preventLazyLoading(!app()->isProduction());` in `AppServiceProvider::boot()`.
- Never load 100k rows into memory: `chunkById()` (by PK, no OFFSET) or `lazy()` (LazyCollection stream) — memory stays flat.
- Read-only reports: skip Eloquent entirely (`DB::table()` → stdClass, no hydration/events/casts).
- Select only what you need + composite index for WHERE+ORDER BY, then verify via `EXPLAIN` (`type: range`, key used — not `ALL`/filesort).

### 7.2 Composite indexes (index/ — note: its .md is a copy-paste of eager-loading; the images carry the real content)
- A composite index is ONE sorted list, left to right. **Leftmost prefix rule**: `INDEX(a, b, c)` serves `a`, `(a,b)`, `(a,b,c)` — never `b` alone.
- **Equality first, range last**: `WHERE status = ? AND created_at > ?` → `INDEX(status, created_at)`, not the reverse. After the first range column, further columns don't narrow the seek.
- Workflow: read the WHERE left-to-right → build the index in that order → `EXPLAIN` to confirm the key is used → repeat per important query. One composite index cannot serve every query — design for the most important ones.

### 7.3 Eager load vs. select subquery (eager-loading/)
`with()` fixed the query count, not the hydration: 50 users × 40 orders = 2,000 hydrated models to read 50 timestamps. Need ONE value from a relation → subquery; need several fields/rows/accessors → keep `with()`.
```php
User::query()->addSelect(['last_order_at' => Order::select('created_at')
    ->whereColumn('user_id', 'users.id')
    ->latest()
    ->limit(1)])
    ->withCasts(['last_order_at' => 'datetime'])
    ->orderByDesc('last_order_at');   // DB-side sort → correct page 1
```
Three rules: (1) one column + `limit(1)`, (2) correlate with `whereColumn` and qualify the outer side (`'users.id'` — plain `where()` compares a literal string and silently returns nothing), (3) result is an attribute → cast via `withCasts()`. Index `(user_id, created_at)` or you moved the cost, not removed it. Sorting a Collection only reorders the fetched page — `orderByDesc` on the subquery sorts before LIMIT.

### 7.4 Mass deletes (delete/)
One large DELETE = one transaction = locks held the whole run; timeout at row 1.9M rolls back everything. The real cost is **lock duration, not row count** — bound the key range and you bound the lock.
```php
// Dispatch one queued job per bounded id range
foreach (range(0, $maxId, 5_000) as $start) {
    PruneOrders::dispatch($start, $start + 5_000);
}
// Inside the job — filter stays in the query, range only bounds the lock
Order::query()
    ->whereBetween('id', [$this->from, $this->to])
    ->where('created_at', '<', now()->subYear())
    ->delete();
```
SQL alternative: `DELETE ... WHERE {filter} AND id > :lastId ORDER BY id LIMIT :batchSize`, commit per batch. Ranges don't overlap → jobs run in parallel, failed ranges retry independently.

### 7.5 Balances & money (balance/)
Read → compute → write-back loses money under concurrency: two requests read 100, both write 70, a deposit vanishes — no error. The column held a number, not a fact.
- **Store the entries, derive the balance** (ledger): every deposit/charge/refund is one immutable row; inserts never overwrite. Debits negative, integer minor units (cents), never floats.
```php
DB::transaction(function () use ($wallet, $cents): void {
    Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
    $balance = $wallet->entries()->sum('amount');
    abort_if($balance + $cents < 0, 422);
    $wallet->entries()->create(['amount' => $cents]);
});
```
- When `sum()` gets slow, reintroduce the balance column as a rebuildable **cache** — stale costs latency, not money. Rule: a column you cannot rebuild from history cannot be audited.

### 7.6 Circuit breaker (critic-breaker/)
A slow dependency makes their outage yours: workers block on timeouts, retries add load to a drowning service. Layer three tools: **timeout** (cap the wait) → **retry** (transient blips) → **circuit breaker** (service is down). States: Closed (count failures) → Open (fail instantly, no network) → Half-open (one probe; success closes, failure re-opens). Decide the fallback BEFORE the outage (cached value, queued job, degraded response).
```php
if (Cache::has('cb:payments')) {
    return $this->fallback();
}

try {
    return Http::timeout(2)->get($url)->throw()->json();
} catch (RequestException) {
    Cache::put('cb:payments', true, now()->addSeconds(30));

    return $this->fallback();
}
```
Add a failure counter so a single bad response doesn't open the circuit. A breaker protects the caller, not the dependency — it turns a 30s timeout into an instant, designable failure.

### 7.7 Shared-DB multi-tenancy (tenancy/)
The failure mode is a missing where clause — `Invoice::find($id)` happily returns another tenant's invoice. Make the filter the default, failing closed:
```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', fn ($q) =>
            $q->where('tenant_id', Tenant::current()->id));

        static::creating(fn ($m) =>
            $m->tenant_id ??= Tenant::current()->id);
    }
}
```
(Nubos concretely: `TenantScope` via `#[ScopedBy]` — same principle.) Rules: `tenant_id` on every tenant-owned row, set on create — never from user input; composite indexes and uniques LEAD with `tenant_id` (`unique(['tenant_id', 'slug'])` — global `unique('slug')` breaks tenants); **edges the scope does not cover**: `DB::table()`, raw SQL, console commands, queued jobs (jobs start with NO tenant — pass the tenant id in and re-resolve before work runs).

### 7.8 Image uploads (resizing/ + monolith post)
`max:5120` caps bytes on disk — decoding pays in pixels: W×H×4 bytes; a 2 MB JPEG at 6000×4000 becomes ~96 MB of bitmap and kills the worker. Bytes lie, pixels don't.
```php
$request->validate([
    'photo' => ['image', 'max:5120', Rule::dimensions()->maxWidth(4000)->maxHeight(4000)],
]);
```
(1) Cap pixels at the door — the dimensions rule uses `getimagesize()`, header-only, no bitmap allocation. (2) Queue the resize with its own memory ceiling — never in the request. (3) Keep the byte cap too. Uploads are a memory budget, not a storage problem.

### 7.9 Taming the monolith with DDD (monolith/)
MVC fails on ownership, not size: one refund rule scattered across controller, observer, job, and Blade. DDD draws the boundary first — group by business capability (`app/Domains/Ordering`, `Billing`, …), each owning its models, actions, events, tests.
- Rules live in Actions, not controllers (`RefundOrder` with constructor-injected gateway: one class, one responsibility). Domains talk through events (`Ordering` fires `OrderPlaced`, `Billing` listens) — no domain imports another's internals; one public entry point per domain; domain tests run without HTTP.
- Adopt when: ≥2 real business areas, >1 team, or a dreaded Models folder. An 8-table CRUD app doesn't need it. Migrate incrementally: extract the messiest capability → prove the boundary → next. DDD is a way to keep changing the code, not a rewrite.

## 8. Known Pitfalls (seed-learnings — read the file before fixing anything related)

| Pitfall | Rule | File |
|---|---|---|
| `Collection::paginate()` loads ALL rows, then slices | Paginate on the Builder; `cursorPaginate()` for large sets | `seed-learnings/general/pagination-with-relations.md` |
| Local timezones break jobs & comparisons | DB/`app.timezone` = UTC always; convert only in the frontend; API returns ISO 8601 `Z`; never `Carbon::setTimezone()` at runtime | `seed-learnings/general/timezone-utc.md` |
| New column missing from `#[Fillable]` is dropped **silently** | Update `#[Fillable]` with every migration; `Model::preventSilentlyDiscardingAttributes(!app()->isProduction())` in dev | `seed-learnings/laravel/mass-assignment-trap.md` |
| FK type mismatch on ULID columns | Always `foreignUlid()` — never `unsignedBigInteger` or `foreignUuid()` against a ulid PK | `seed-learnings/laravel/migration-foreign-key.md` |
| N+1 invisible in small datasets | `with()` before iteration; `preventLazyLoading()` in dev | `seed-learnings/laravel/n-plus-one.md` |
| Unique constraint blocks re-creating soft-deleted values | Partial unique index `WHERE deleted_at IS NULL` (PostgreSQL) | `seed-learnings/laravel/soft-delete-unique.md` |
| `ORDER BY id` is only chronological because keys are ULID | ULIDs sort lexicographically by creation time, so `orderBy('id')` is a valid deterministic and chronological order. It would break the moment a table switched to UUID — which D5.1 forbids | `seed-learnings/laravel/uuid-ordering.md` |
| Capacitor plugin call without permission | `checkPermissions()` → `requestPermissions()` → call; handle denial | `seed-learnings/react/capacitor-permission.md` |
| Listeners in `onMounted` never removed → leak | Cleanup in `onUnmounted`; `useEventListener` composable; also intervals/sockets/observers | `seed-learnings/vue/memory-leak-events.md` |
| `items.value[1] = x` may not trigger reactivity | `splice`/`push`/`filter`/`map` or reassign the ref | `seed-learnings/vue/reactivity-pitfall.md` |

## 9. Routing Table — read before you touch it

All paths relative to the repo root. **Templates first**: never write a new artifact from scratch — copy the template and replace placeholders.

| Working on … | Read (conventions) | Template |
|---|---|---|
| Action | `knowledge/knowledge-base/conventions/laravel/actions.md` | `knowledge/knowledge-base/templates/laravel/action.md` |
| Controller | `…/laravel/controllers.md` | `…/templates/laravel/controller-single.md`, `controller-resource.md` |
| Model | `…/laravel/models.md` | `…/templates/laravel/model.md` |
| Migration / Pivot | `…/laravel/migrations.md`, `…/laravel/pivot-tables.md` | `…/templates/laravel/migration.md` |
| API Resource | `…/laravel/api-resources.md` | — |
| DTO / Value Object | `…/laravel/dtos.md` | — |
| Enum | `…/laravel/enums.md` | `…/templates/laravel/enum.md` |
| Outbox / Activity | `app/Support/Engine/OutboxRelay.php`, `app/Activities/{Domain}/` | — (no template; Laravel events are banned here) |
| Policy | `…/laravel/policies.md` | `…/templates/laravel/policy.md` |
| Service | `…/laravel/services.md` | `…/templates/laravel/service.md` |
| FormRequest | `…/laravel/form-requests.md` | `…/templates/laravel/form-request.md` |
| Command | `…/laravel/commands.md` | `…/templates/laravel/command.md` |
| Middleware / Routing | `…/laravel/middleware.md`, `…/laravel/routing.md` | `…/templates/laravel/middleware.md` |
| Factory / Seeder | `…/laravel/factories.md`, `…/laravel/seeders.md` | `…/templates/laravel/factory.md`, `seeder.md` |
| Config | `…/laravel/configuration.md` | — |
| Laravel tests | `…/laravel/testing.md` | `…/templates/laravel/pest-feature.md`, `pest-unit.md` |
| PHP style / class layout | `…/laravel/code-style.md`, `…/laravel/class-anatomy.md`, `…/laravel/security.md` | — |
| Vue component / page | `…/vue/component.md`, `…/vue/naming.md` | `…/templates/vue/component.md`, `page.md` |
| Composable / store | `…/vue/composables.md` | `…/templates/vue/composable.md` |
| Inertia / TS / model+ref binding | `…/vue/inertia.md`, `…/vue/typescript.md`, `…/vue/two-way-binding.md`, `…/vue/template-ref.md` | `…/templates/vue/two-way-binding.md`, `template-ref.md` |
| Vue tests / security | `…/vue/testing.md`, `…/vue/security.md` | — |
| React component / hook | `…/react/components.md`, `…/react/hooks.md`, `…/react/naming.md`, `…/react/typescript.md` | `…/templates/react/component.md`, `hook.md`, `page.md` |
| Capacitor / mobile security | `…/react/capacitor.md`, `…/react/security.md` | — |
| C# (Temporal services etc.) | `…/c-sharp/architecture.md`, `code-style.md`, `controllers.md`, `dtos.md`, `models.md`, `naming.md`, `security.md`, `services.md`, `testing.md`; Temporal: `…/general/temporal.md` | `…/templates/c-sharp/*` |
| Cross-cutting | `…/general/`: `architecture.md`, `solid.md`, `design-patterns.md`, `api-design.md`, `error-handling.md`, `logging.md`, `caching.md`, `database-patterns.md`, `performance.md`, `security.md`, `security-advanced.md`, `testing-strategy.md`, `git.md` | — |
| Deep-dive perf/data topics | `knowledge/laravel-knowledge/` (transcribed in Section 7) | — |

**UI/UX work** → `knowledge/knowledge-base/ux-patterns/{context}/`: desktop-backoffice (tables, forms, navigation, dashboards, bulk-operations), mobile-field (lists, data-entry, navigation, offline, feedback), tablet-kiosk (layout, input, accessibility), dashboard-reporting (kpi-cards, charts, filters, export), data-entry-form (validation, auto-save, smart-defaults, progressive-disclosure), approval-workflow (actions, detail-view, notifications), plus: loading, empty-states, search, wizards, inline-editing, drag-drop, keyboard-shortcuts, gestures, permissions-ui, realtime, responsive, settings, ios-native, android-native. Review rules: `knowledge/knowledge-base/conventions/ux-critic-rules/{general,desktop,mobile,ios,android,approval-workflow}.md`.

**Workflows (step-by-step playbooks)** → `knowledge/agent-skills/`: Developer (`implement-feature`, `create-model`, `create-api-endpoint`, `fix-bug`), Critic (`review`, `security-review`), Lead (`architecture-decision`, `solution-design`, `ticket-decomposition`), PM (`requirements-analysis`, `pipeline-orchestration`, `swarm-coordination`), DevOps (`deploy`, `pipeline-setup`, `preview-management`), SecurityAgent (`security-audit`, `dependency-check`), Researcher, KeyAccounter, PatchAgent. When implementing a feature, follow `Developer/implement-feature/SKILL.md`: conventions → template → existing schema → learnings → naming → **audit existing code** → implement incrementally (model → migration → action → controller → tests) → quality gates.

**Quality gates (phase checklists)** → `knowledge/knowledge-base/quality-gates/{planning,development,review,deployment,compound}.md`.

## 10. Definition of Done (from quality-gates development + review)

Before hand-off, ALL of these hold:
- [ ] `./vendor/bin/phpstan analyse` clean (Level 6, tests included) — no baseline additions
- [ ] `./vendor/bin/pint` run on all changed files; `pint --test` passes
- [ ] `php artisan test --compact` green — tests MUST be green, no exceptions
- [ ] `npm run tsc --noEmit` passes (strict); frontend build succeeds
- [ ] No `env()` outside `config/`; no `DB::` facade in Models; `::query()` on every Eloquent DB call
- [ ] Every new model has Migration + Factory + Policy + Tests; every endpoint has Policy check + Resource response
- [ ] Feature tests: happy path (2xx + DB asserted), validation errors (422 + fields), auth (401 + 403 wrong tenant), edge cases. Unit tests for new Actions/Services. Factory states cover all enum variants
- [ ] No N+1, no unbounded queries, no queries in loops
- [ ] No duplicate/dead code, no unused imports, no pattern mixing, dependency direction respected, no cross-domain Action calls
- [ ] No injection/mass-assignment/XSS risk; no hardcoded secrets
- [ ] UI: touch targets ≥ 44×44px (mobile), critical path ≤ 3 clicks, loading/error states handled, destructive actions confirm
- [ ] Git: `feature/{TICKET}-{desc}` branch, Conventional Commits (`feat(scope): …`), squash-merge, PR < 400 lines, ticket ID in branch + PR title

## 11. Known Conflicts in the Source (rulings)

The knowledge lib contradicts itself in four places. These rulings apply until the sources are aligned:

- **K1 — Migration `down()`**: `conventions/laravel/migrations.md` (v1.0) requires `down()`; `quality-gates/deployment.md` (v2.0) and `agent-skills/Developer/create-model` forbid it (forward-only). **Ruling: always ship a reversible `down()`** — CLAUDE.md › Project Rules mandates it and the codebase follows it (63 of 70 migrations).
- **K2 — Mobile token storage**: `react/capacitor.md` example stores `auth_token` via `Preferences`; `react/security.md` (v2.0) forbids exactly that. **Ruling: SecureStoragePlugin (Keychain/Keystore) for tokens; Preferences only for non-sensitive settings.**
- **K3 — Error envelope**: `general/api-design.md`/`error-handling.md` specify `{"error": {"message","code","details"}}`; `laravel/api-resources.md` specifies `{"type","message","errors"}` (what the ExceptionHandler produces). **Ruling: follow the existing ExceptionHandler implementation in the codebase; don't introduce a second shape.**
- **K4 — Member ordering**: `laravel/class-anatomy.md` says public → protected → private; `quality-gates/development.md` says private → protected → public. **Ruling: follow `class-anatomy.md` (public first) — it is the dedicated convention. Above all: match the file you are editing.**

