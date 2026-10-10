<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Ui\NavIcon;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UiModuleRegistry;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Teams\ActiveTeamUrlDefault;
use App\Support\Teams\TeamSegment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    URL::defaults([TeamSegment::key() => 'nubos']);

    $modules = new UiModuleRegistry(
        [],
        new class extends ModuleRegistry
        {
            /**
             * @return list<string>
             */
            public function all(): array
            {
                return [];
            }
        },
        app(ModuleCatalog::class),
    );

    $this->builder = new NavigationBuilder(
        new ActiveTeamUrlDefault,
        app(ObjectTypeBackingRegistry::class),
        $modules,
    );

    /** @var callable(string, array<string, mixed>):ObjectType */
    $this->objectType = fn (string $slug, array $attributes = []): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($slug),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'is_navigable' => true,
        'nav_position' => 100,
        'nav_icon' => NavIcon::LayoutGrid,
        ...$attributes,
    ]);

    /** @var callable(list<ObjectType>):Collection<int, ObjectType> */
    $this->typesOf = static fn (array $types): Collection => new Collection($types);

    /** @var callable(list<string>):array<string, bool> */
    $this->granting = static fn (array $abilities): array => array_fill_keys($abilities, true);

    /** @var callable(list<Role>):User */
    $this->actor = fn (array $roles = []): User => RoleHolder::make(
        ['tenant_id' => (string) $this->tenant->getKey()],
        $roles,
    );

    /** @var callable(array<string, mixed>):list<string> */
    $this->keysUnder = static function (array $nodes) use (&$keysUnder): array {
        $keys = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (is_string($node['key'] ?? null)) {
                $keys[] = $node['key'];
            }

            $keys = [...$keys, ...($keysUnder)($node['children'] ?? $node['items'] ?? [])];
        }

        return $keys;
    };
    $keysUnder = $this->keysUnder;

    /** @var callable(array<string, mixed>):list<string> */
    $this->hrefsUnder = static function (array $nodes) use (&$hrefsUnder): array {
        $hrefs = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (is_string($node['href'] ?? null)) {
                $hrefs[] = $node['href'];
            }

            $hrefs = [...$hrefs, ...($hrefsUnder)($node['children'] ?? $node['items'] ?? [])];
        }

        return $hrefs;
    };
    $hrefsUnder = $this->hrefsUnder;
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
});

it('builds nothing at all while no team segment is active', function (): void {
    URL::defaults([TeamSegment::key() => null]);

    expect($this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['companies.view'])))
        ->toBe(['main' => [], 'configuration' => []]);
});

it('lists only the object types the acting user may view', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies'), ($this->objectType)('contracts')]),
        ($this->granting)(['companies.view']),
    );

    expect(($this->keysUnder)($tree['main']))->toContain('record-companies')
        ->and(($this->keysUnder)($tree['main']))->not->toContain('record-contracts');
});

it('leaves an object type flagged as not navigable out of the menu', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies', ['is_navigable' => false])]),
        ($this->granting)(['companies.view']),
    );

    expect(($this->keysUnder)($tree['main']))->not->toContain('record-companies');
});

it('orders the record entries by navigation position and then by name', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([
            ($this->objectType)('zebras', ['nav_position' => 10]),
            ($this->objectType)('apples', ['nav_position' => 100]),
            ($this->objectType)('bananas', ['nav_position' => 100]),
        ]),
        ($this->granting)(['zebras.view', 'apples.view', 'bananas.view']),
    );

    expect(array_values(array_filter(
        ($this->keysUnder)($tree['main']),
        static fn (string $key): bool => str_starts_with($key, 'record-'),
    )))->toBe(['record-zebras', 'record-apples', 'record-bananas']);
});

it('addresses the record section by a key so a module can relabel it', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view']),
    );

    expect($tree['main'][1]['key'])->toBe('records');
});

it('always offers the personal entries even without a single visible object type', function (): void {
    $tree = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);

    expect(($this->keysUnder)($tree['main']))->toBe(['dashboard', 'reminders', 'approvals']);
});

it('opens the analytics group exactly when a record section is offered', function (): void {
    $withRecords = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view']),
    );

    $withoutRecords = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);

    expect(($this->keysUnder)($withRecords['configuration']))->toContain('cfg-analytics', 'reports', 'dashboards', 'goals')
        ->and(($this->keysUnder)($withoutRecords['configuration']))->not->toContain('cfg-analytics');
});

it('keeps the analytics leaves out of the main navigation so they never appear twice', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view']),
    );

    expect(($this->keysUnder)($tree['main']))->not->toContain('reports')
        ->and(($this->keysUnder)($tree['main']))->not->toContain('dashboards')
        ->and(($this->keysUnder)($tree['main']))->not->toContain('goals');
});

it('lists analytics as the first group of the configuration node', function (): void {
    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view', 'object-types.view']),
    );

    expect($tree['configuration'][0]['children'][0]['key'])->toBe('cfg-analytics');
});

it('offers the data model group only to a holder of the object type permission', function (): void {
    $without = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);
    $with = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['object-types.view']));

    expect(($this->keysUnder)($without['configuration']))->not->toContain('object-types')
        ->and(($this->keysUnder)($with['configuration']))->toContain('object-types', 'relationship-types');
});

it('gates every settings entry behind its own permission and always offers segments', function (): void {
    $bare = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);
    $full = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)([
        'reminder-types.view', 'activity-types.view', 'skills.view',
    ]));

    expect(($this->keysUnder)($bare['configuration']))->toContain('segments')
        ->and(($this->keysUnder)($bare['configuration']))->not->toContain('reminder-types')
        ->and(($this->keysUnder)($bare['configuration']))->not->toContain('activity-types')
        ->and(($this->keysUnder)($bare['configuration']))->not->toContain('skills')
        ->and(($this->keysUnder)($full['configuration']))->toContain('reminder-types', 'activity-types', 'skills');
});

it('hides the administration node entirely from a user without a single administrative right', function (): void {
    $tree = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);

    expect(($this->keysUnder)($tree['configuration']))->not->toContain('administration');
});

it('opens the access group per permission', function (): void {
    $tree = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)([
        'members.view', 'roles.view',
    ]));

    expect(($this->keysUnder)($tree['configuration']))->toContain('administration', 'adm-access', 'users', 'roles')
        ->and(($this->keysUnder)($tree['configuration']))->not->toContain('teams')
        ->and(($this->keysUnder)($tree['configuration']))->not->toContain('personalization');
});

it('opens the operations group for config as code and for maintenance separately', function (): void {
    $export = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['config.export']));
    $maintenance = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['maintenance.manage']));

    expect(($this->keysUnder)($export['configuration']))->toContain('config-as-code')
        ->and(($this->keysUnder)($export['configuration']))->not->toContain('maintenance')
        ->and(($this->keysUnder)($maintenance['configuration']))->toContain('maintenance')
        ->and(($this->keysUnder)($maintenance['configuration']))->not->toContain('config-as-code');
});

it('reserves the webhook entry for an escalated authority', function (): void {
    AccessContext::grant();

    $plain = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['members.view']));

    $escalated = $this->builder->build(
        ($this->actor)([ModelStub::make(Role::class, ['authority' => RoleAuthority::SuperAdmin])]),
        ($this->typesOf)([]),
        ($this->granting)(['members.view']),
    );

    expect(($this->keysUnder)($plain['configuration']))->not->toContain('webhooks')
        ->and(($this->keysUnder)($escalated['configuration']))->toContain('webhooks', 'api-tokens');
});

it('opens the api token entry to a plain holder of the api token permission', function (): void {
    AccessContext::grant('api-tokens.manage');

    $tree = $this->builder->build(($this->actor)(), ($this->typesOf)([]), ($this->granting)(['members.view']));

    expect(($this->keysUnder)($tree['configuration']))->toContain('api-tokens')
        ->and(($this->keysUnder)($tree['configuration']))->not->toContain('webhooks');
});

it('prefixes every link with the team segment the page is served under', function (): void {
    AccessContext::grant();

    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view', 'object-types.view', 'members.view']),
    );

    $hrefs = [...($this->hrefsUnder)($tree['main']), ...($this->hrefsUnder)($tree['configuration'])];

    expect($hrefs)->not->toBeEmpty();

    foreach ($hrefs as $href) {
        expect($href)->toStartWith('/nubos/');
    }
});

it('follows the segment the request is served under instead of a fixed one', function (): void {
    URL::defaults([TeamSegment::key() => '01JBRT0000000000000000000A']);

    $tree = $this->builder->build(($this->actor)(), ($this->typesOf)([]), []);

    foreach (($this->hrefsUnder)($tree['main']) as $href) {
        expect($href)->toStartWith('/01JBRT0000000000000000000A/');
    }
});

it('names an icon on every entry that the frontend icon map knows', function (): void {
    AccessContext::grant();

    $tree = $this->builder->build(
        ($this->actor)(),
        ($this->typesOf)([($this->objectType)('companies')]),
        ($this->granting)(['companies.view', 'object-types.view', 'members.view', 'maintenance.manage']),
    );

    $known = array_map(static fn (NavIcon $icon): string => $icon->value, NavIcon::cases());

    $collect = static function (array $nodes) use (&$collect, $known): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['icon'])) {
                expect($known)->toContain($node['icon']);
            }

            $collect($node['children'] ?? $node['items'] ?? []);
        }
    };

    $collect($tree['main']);
    $collect($tree['configuration']);
});
