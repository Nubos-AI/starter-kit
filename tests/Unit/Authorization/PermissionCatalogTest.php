<?php

declare(strict_types=1);

use App\Enums\Authorization\CrudAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Models\ObjectType;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Support\Facades\Config;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $configured = (array) require config_path('permissions.php');

    Config::set('permissions.tabs', $configured['tabs']);
    Config::set('permissions.groups', $configured['groups']);

    $this->catalog = app(PermissionCatalog::class);

    /** @var callable(string):ObjectType */
    $this->objectType = fn (string $slug): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($slug),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => $slug,
        'name' => ucfirst($slug),
    ]);

    /** @var callable(string):list<string> */
    $this->typeUnion = function (string $name): array {
        $source = (string) file_get_contents(base_path('resources/js/types/permissions.ts'));

        if (preg_match('/export type '.$name.' =(.*?);/s', $source, $block) !== 1) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $block[1], $literals);

        return $literals[1];
    };

    /** @var callable(string):array<string, string> */
    $this->labelMap = function (string $constant): array {
        $source = (string) file_get_contents(base_path('resources/js/lib/permissionLabels.ts'));

        if (preg_match('/'.preg_quote($constant, '/').'[^=]*=\s*\{(.*?)\n\};/su', $source, $block) !== 1) {
            return [];
        }

        preg_match_all("/'?([A-Za-z0-9_.\-]+)'?\s*:\s*'([^']*)'/u", $block[1], $pairs, PREG_SET_ORDER);

        $labels = [];

        foreach ($pairs as $pair) {
            $labels[$pair[1]] = $pair[2];
        }

        return $labels;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers the crud actions plus the object type specific abilities', function (): void {
    $expected = [
        ...array_map(static fn (CrudAction $action): string => $action->value, CrudAction::cases()),
        ...array_map(static fn (ObjectTypeAbility $ability): string => $ability->value, ObjectTypeAbility::cases()),
    ];

    expect($this->catalog->objectTypeAbilities())->toEqualCanonicalizing($expected)
        ->and($this->catalog->objectTypeAbilities())->toHaveCount(count($expected));
});

it('carries a case for the audit view right', function (): void {
    expect(ObjectTypeAbility::tryFrom('audit.view'))->toBe(ObjectTypeAbility::AuditView)
        ->and($this->catalog->objectTypeAbilities())->toContain('audit.view');
});

it('mirrors the configured groups into the global names', function (): void {
    $expected = [];

    foreach ((array) config('permissions.groups', []) as $group => $meta) {
        foreach ((array) ((array) $meta)['actions'] as $action) {
            $expected[] = $group.'.'.$action;
        }
    }

    expect($this->catalog->globalNames())->toEqualCanonicalizing($expected);
});

it('prefixes every ability of an object type with its slug', function (): void {
    $names = $this->catalog->namesForObjectType(($this->objectType)('companies'));

    expect($names)->toContain('companies.view', 'companies.delete', 'companies.rules.manage', 'companies.audit.view')
        ->and($names)->toHaveCount(count($this->catalog->objectTypeAbilities()));
});

it('spans the global names and every object type it is handed without a query of its own', function (): void {
    $companies = ($this->objectType)('companies');

    $all = $this->catalog->all(collect([$companies]));

    expect($all)->toEqualCanonicalizing([
        ...$this->catalog->globalNames(),
        ...$this->catalog->namesForObjectType($companies),
    ])
        ->and(array_is_list($all))->toBeTrue()
        ->and(array_unique($all))->toHaveCount(count($all));
});

it('registers the operations abilities as separate catalog entries', function (): void {
    $names = $this->catalog->globalNames();

    expect($names)->toContain(
        'promotions.execute',
        'promotions.approve',
        'config.export',
        'config.import',
        'maintenance.manage',
    );
});

it('registers the governance abilities for quality gates routing approvals and absences', function (): void {
    $names = $this->catalog->globalNames();

    expect($names)->toContain('quality-gates.configure', 'routing.configure')
        ->and($names)->toContain('absences.view', 'absences.manage')
        ->and($names)->toContain('approvals.view', 'approvals.decide', 'approvals.configure');
});

it('files the operations groups under a dropdown tab that sits right before the records tab', function (): void {
    foreach (['promotions', 'config', 'maintenance'] as $group) {
        expect(config("permissions.groups.{$group}.tab"))->toBe('operations');
    }

    $tabKeys = array_map(strval(...), array_keys((array) config('permissions.tabs')));

    expect(config('permissions.tabs.operations.type'))->toBe('dropdown')
        ->and(array_slice($tabKeys, -2))->toBe(['operations', 'records']);
});

it('keeps every permission group reachable through a configured tab', function (): void {
    $tabKeys = array_map(strval(...), array_keys((array) config('permissions.tabs')));

    foreach ((array) config('permissions.groups') as $meta) {
        expect($tabKeys)->toContain((string) ((array) $meta)['tab']);
    }
});

it('reserves an object type slug for every permission group', function (): void {
    $reserved = array_map(strval(...), (array) config('engine.reserved_slugs'));
    $groups = array_map(strval(...), array_keys((array) config('permissions.groups')));

    expect(array_values(array_diff($groups, $reserved)))->toBe([]);
});

it('mirrors the php abilities into the typescript unions', function (): void {
    expect(($this->typeUnion)('ObjectTypeAbility'))->toEqualCanonicalizing($this->catalog->objectTypeAbilities())
        ->and(($this->typeUnion)('GlobalPermission'))->toEqualCanonicalizing($this->catalog->globalNames());
});

it('mirrors every configured group label into the typescript label map', function (): void {
    $labels = ($this->labelMap)('GROUP_LABELS');

    expect($labels)->not->toBe([]);

    foreach (array_keys((array) config('permissions.groups')) as $group) {
        $group = (string) $group;

        expect($labels)->toHaveKey($group)
            ->and($labels[$group])->toBe(__((string) config("permissions.groups.{$group}.label")));
    }
});

it('mirrors every configured action into the typescript action label map', function (): void {
    $labels = ($this->labelMap)('ACTION_LABELS');

    expect($labels)->not->toBe([]);

    foreach ((array) config('permissions.groups') as $group) {
        foreach ((array) ((array) $group)['actions'] as $action) {
            $action = (string) $action;

            expect($labels)->toHaveKey($action)
                ->and($labels[$action])->not->toBe('')
                ->and(mb_strtolower($labels[$action]))->not->toBe($action);
        }
    }
});
