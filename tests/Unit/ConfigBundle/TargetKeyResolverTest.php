<?php

declare(strict_types=1);

use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\ConfigBundle\TargetKeyResolver;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->dealId = ModelStub::ulid('object-type-deal');
    $this->regionalId = ModelStub::ulid('object-type-deal-eu');
    $this->regionalGroupId = ModelStub::ulid('field-group-basis');
    $this->dashboardId = ModelStub::ulid('dashboard-sales');
    $this->firstWidgetId = ModelStub::ulid('widget-first');
    $this->secondWidgetId = ModelStub::ulid('widget-second');
    $this->coreTeamId = ModelStub::ulid('team-core');

    $this->rows = fn (): array => [
        'object_types' => [
            ['id' => $this->dealId, 'key' => 'deal', 'name' => 'Deal'],
            ['id' => $this->regionalId, 'key' => 'deal:eu', 'name' => 'Deal EU'],
        ],
        'field_groups' => [
            ['id' => $this->regionalGroupId, 'object_type_id' => $this->regionalId, 'key' => 'basis'],
        ],
        'dashboards' => [
            ['id' => $this->dashboardId, 'name' => 'Sales Overview', 'is_tenant_wide' => true],
        ],
        'dashboard_widgets' => [
            ['id' => $this->firstWidgetId, 'dashboard_id' => $this->dashboardId, 'title' => 'Pipeline Tile', 'position' => 1],
            ['id' => $this->secondWidgetId, 'dashboard_id' => $this->dashboardId, 'title' => 'Revenue Tile', 'position' => 2],
        ],
        'teams' => [
            ['id' => $this->coreTeamId, 'slug' => 'core', 'name' => 'Core'],
        ],
    ];

    /** @var callable(array<string, list<array<string, mixed>>>|callable):TargetKeyResolver */
    $this->resolverOver = static fn (array|callable $rows): TargetKeyResolver => new class($rows) extends TargetKeyResolver
    {
        /**
         * @var list<string>
         */
        public array $readTables = [];

        /**
         * @param  array<string, list<array<string, mixed>>>|callable(string):array<string, list<array<string, mixed>>>  $rows
         */
        public function __construct(private $rows) {}

        /**
         * @param  array<string, mixed>  $entry
         * @param  array<string, list<array<string, mixed>>>  $rowsByTable
         * @return list<array<string, mixed>>
         */
        protected function rowsOf(array $entry, string $tenantId, array $rowsByTable): array
        {
            $table = (string) $entry['table'];
            $this->readTables[] = $table;

            $rows = is_callable($this->rows) ? ($this->rows)($tenantId) : $this->rows;

            return $rows[$table] ?? [];
        }
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('answers a known object type key with the identifier and an unknown key with null', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->idFor(ArtifactKind::ObjectTypes, 'deal'))->toBe($this->dealId)
        ->and($resolver->idFor('object_types', 'deal'))->toBe($this->dealId)
        ->and($resolver->idFor(ArtifactKind::ObjectTypes, 'gibt-es-nicht'))->toBeNull()
        ->and($resolver->idFor('gibt_es_nicht', 'deal'))->toBeNull();
});

it('reads every row of the bound tenant behind a tenant qualified query instead of reaching across the boundary', function (): void {
    $resolver = new TargetKeyResolver;

    $shape = QueryShape::attemptedBy(fn (): ?string => $resolver->idFor(ArtifactKind::ObjectTypes, 'deal'));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?');
});

it('answers nothing at all while no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->idFor(ArtifactKind::ObjectTypes, 'deal'))->toBeNull()
        ->and($resolver->readTables)->toBe([]);
});

it('derives a parent by the longest known prefix so an object type key that carries a colon stays intact', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->parentIdFor(ArtifactKind::FieldGroups, 'deal:eu:basis', 'object_type_id'))->toBe($this->regionalId)
        ->and($resolver->parentIdFor(ArtifactKind::FieldGroups, 'deal:eu:basis', 'object_type_id'))->not->toBe($this->dealId)
        ->and($resolver->idFor(ArtifactKind::FieldGroups, 'deal:eu:basis'))->toBe($this->regionalGroupId)
        ->and($resolver->knownPrefixOf(ArtifactKind::ObjectTypes, 'deal:eu:basis'))->toBe('deal:eu');
});

it('derives the dashboard of a widget by cutting off the trailing ordinal component', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->parentIdFor(ArtifactKind::DashboardWidgets, 'Sales Overview:1', 'dashboard_id'))
        ->toBe($this->dashboardId);
});

it('resolves a team key made of the bare team slug and leaves a qualified or unknown slug unresolved', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->idFor('teams', 'core'))->toBe($this->coreTeamId)
        ->and($resolver->idFor('teams', 'alpha-region:core'))->toBeNull()
        ->and($resolver->idFor('teams', 'gibt-es-nicht'))->toBeNull();
});

it('derives the object type of a team access rule from the components behind the team', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    expect($resolver->parentIdFor(ArtifactKind::TeamRecordAccessRules, 'core:deal:eu', 'object_type_id'))
        ->toBe($this->regionalId)
        ->and($resolver->parentIdFor(ArtifactKind::TeamRecordAccessRules, 'core:deal', 'object_type_id'))
        ->toBe($this->dealId);
});

it('keeps a row created after the index was built invisible until the kind has been invalidated', function (): void {
    $laterId = ModelStub::ulid('reminder-type-later');
    $rows = ($this->rows)();
    $published = false;

    $resolver = ($this->resolverOver)(function () use ($rows, $laterId, &$published): array {
        return [
            ...$rows,
            'reminder_types' => $published ? [['id' => $laterId, 'name' => 'Nachfassen']] : [],
        ];
    });

    expect($resolver->idFor(ArtifactKind::ReminderTypes, 'Nachfassen'))->toBeNull();

    $published = true;

    expect($resolver->idFor(ArtifactKind::ReminderTypes, 'Nachfassen'))->toBeNull();

    $resolver->invalidate(ArtifactKind::ReminderTypes);

    expect($resolver->idFor(ArtifactKind::ReminderTypes, 'Nachfassen'))->toBe($laterId);
});

it('rereads only the stale tables on an invalidation and leaves the others alone', function (): void {
    $resolver = ($this->resolverOver)(($this->rows)());

    $resolver->idFor(ArtifactKind::ObjectTypes, 'deal');

    $firstPass = $resolver->readTables;

    $resolver->invalidate(ArtifactKind::ReminderTypes);
    $resolver->idFor(ArtifactKind::ObjectTypes, 'deal');

    $secondPass = array_slice($resolver->readTables, count($firstPass));

    expect($firstPass)->toContain('object_types')
        ->and($firstPass)->toContain('reminder_types')
        ->and($secondPass)->toBe(['reminder_types'])
        ->and($resolver->idFor(ArtifactKind::ObjectTypes, 'deal'))->toBe($this->dealId);
});

it('keeps the frozen widget order of a dashboard across an invalidation so a widget added meanwhile shifts no existing key', function (): void {
    $intruderId = ModelStub::ulid('widget-intruder');
    $rows = ($this->rows)();
    $joined = false;

    $resolver = ($this->resolverOver)(function () use ($rows, $intruderId, &$joined): array {
        if (!$joined) {
            return $rows;
        }

        return [
            ...$rows,
            'dashboard_widgets' => [
                ['id' => $intruderId, 'dashboard_id' => $this->dashboardId, 'title' => 'AAA Tile', 'position' => 0],
                ...$rows['dashboard_widgets'],
            ],
        ];
    });

    $firstBefore = $resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:1');
    $secondBefore = $resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:2');

    expect($firstBefore)->toBe($this->firstWidgetId)
        ->and($secondBefore)->toBe($this->secondWidgetId);

    $joined = true;
    $resolver->invalidate(ArtifactKind::DashboardWidgets);

    expect($resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:1'))->toBe($firstBefore)
        ->and($resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:2'))->toBe($secondBefore)
        ->and($resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:3'))->toBeNull()
        ->and($resolver->idFor(ArtifactKind::DashboardWidgets, 'Sales Overview:1'))->not->toBe($intruderId);
});

it('drops the whole index when another tenant is bound', function (): void {
    $otherTenantDealId = ModelStub::ulid('other-tenant-deal');
    $rows = ($this->rows)();

    $resolver = ($this->resolverOver)(function (string $tenantId) use ($rows, $otherTenantDealId): array {
        if ($tenantId === $this->tenantId) {
            return $rows;
        }

        return ['object_types' => [['id' => $otherTenantDealId, 'key' => 'deal', 'name' => 'Deal']]];
    });

    expect($resolver->idFor(ArtifactKind::ObjectTypes, 'deal'))->toBe($this->dealId);

    AccessContext::forgetTenant();
    AccessContext::tenant('other-tenant');

    expect($resolver->idFor(ArtifactKind::ObjectTypes, 'deal'))->toBe($otherTenantDealId)
        ->and($resolver->idFor(ArtifactKind::FieldGroups, 'deal:eu:basis'))->toBeNull();
});

it('never writes a model from the resolution path', function (): void {
    $path = base_path('app/Support/ConfigBundle/TargetKeyResolver.php');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);

    expect(preg_match_all('/::query\(\)->(create|update|delete|insert|firstOrCreate|updateOrCreate)/', $source))->toBe(0)
        ->and(preg_match_all('/->(save|delete|forceDelete)\(\)/', $source))->toBe(0)
        ->and(preg_match_all('/DB::(table|statement|insert|update|delete)/', $source))->toBe(0);
});
