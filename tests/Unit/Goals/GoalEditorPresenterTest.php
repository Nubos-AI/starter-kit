<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\SystemFilterField;
use App\Models\FieldDefinition;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalDefinitionSource;
use App\Support\Goals\GoalEditorPresenter;
use App\Support\Goals\GoalTargetTeamResolver;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\TenantUserOptions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use Tests\Unit\Goals\Doubles\StaticGoalDefinitionSource;
use Tests\Unit\Goals\Doubles\StaticGoalTargetTeamResolver;
use Tests\Unit\Goals\Doubles\StaticTenantTeamOptions;
use Tests\Unit\Goals\Doubles\StaticTenantUserOptions;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->companies = ModelStub::ulid('companies');
    $this->deals = ModelStub::ulid('deals');
    $this->source = new StaticGoalDefinitionSource;
    $this->visibleReportIds = [];

    app()->instance(GoalDefinitionSource::class, $this->source);
    app()->instance(GoalTargetTeamResolver::class, new StaticGoalTargetTeamResolver);
    app()->instance(TenantTeamOptions::class, new StaticTenantTeamOptions([
        ['value' => ModelStub::ulid('sales-team'), 'label' => 'Sales'],
    ]));
    app()->instance(TenantUserOptions::class, new StaticTenantUserOptions([
        [
            'value' => ModelStub::ulid('holder'),
            'label' => 'Dana Holder',
            'description' => 'dana@example.test',
            'avatar' => ['name' => 'Dana Holder'],
        ],
    ]));

    Gate::before(function (?Authenticatable $user, string $ability, array $arguments = []): ?bool {
        $subject = $arguments[0] ?? null;

        if ($ability !== 'view' || !$subject instanceof Report) {
            return null;
        }

        return in_array((string) $subject->getKey(), $this->visibleReportIds, true);
    });

    /** @var callable(string, string, array<string, mixed>):Report */
    $this->report = function (string $seed, string $objectTypeId, array $attributes = []): Report {
        $report = ModelStub::make(Report::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $objectTypeId,
            'name' => $seed,
            'group_by_field_key' => null,
            'series_field_key' => null,
            ...$attributes,
        ]);

        $this->source->withReport($report);
        $this->visibleReportIds[] = (string) $report->getKey();

        return $report;
    };

    /** @var callable(string, string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = function (string $objectTypeId, string $key, FieldType $type, array $attributes = []): FieldDefinition {
        $field = ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-'.$objectTypeId.'-'.$key),
            'object_type_id' => $objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'i18n_labels' => ['en' => ucfirst($key)],
            'is_filterable' => true,
            'is_encrypted' => false,
            'is_translatable' => false,
            ...$attributes,
        ]);

        $this->source->withField($objectTypeId, $field);

        return $field;
    };

    /** @var callable():array<string, mixed> */
    $this->payload = fn (): array => app(GoalEditorPresenter::class)->payload(
        ModelStub::make(User::class, [
            'id' => ModelStub::ulid('actor'),
            'tenant_id' => $this->tenant->getKey(),
        ]),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(GoalDefinitionSource::class);
    app()->forgetInstance(GoalTargetTeamResolver::class);
    app()->forgetInstance(TenantTeamOptions::class);
    app()->forgetInstance(TenantUserOptions::class);
});

it('leaves a report the actor may not view out of the list entirely', function (): void {
    $visible = ($this->report)('visible-report', $this->companies);
    $hidden = ($this->report)('hidden-report', $this->companies);

    $this->visibleReportIds = [(string) $visible->getKey()];

    $payload = ($this->payload)();

    expect(array_column($payload['reportOptions'], 'value'))->toBe([(string) $visible->getKey()])
        ->and($payload['scopeFieldsByReport'])->not->toHaveKey((string) $hidden->getKey())
        ->and($payload['periodFieldsByReport'])->not->toHaveKey((string) $hidden->getKey());
});

it('keeps a grouped report selectable but disabled and names the reason', function (): void {
    ($this->report)('single-report', $this->companies);
    ($this->report)('grouped-report', $this->companies, ['group_by_field_key' => 'stage']);
    ($this->report)('series-report', $this->companies, ['series_field_key' => 'created_at']);

    $options = collect(($this->payload)()['reportOptions'])->keyBy('value');

    expect($options[ModelStub::ulid('grouped-report')]['disabled'])->toBeTrue()
        ->and($options[ModelStub::ulid('grouped-report')]['disabledReason'])->toBe('grouped_report')
        ->and($options[ModelStub::ulid('series-report')]['disabled'])->toBeTrue()
        ->and($options[ModelStub::ulid('single-report')])->not->toHaveKey('disabled')
        ->and($options[ModelStub::ulid('single-report')])->not->toHaveKey('disabledReason');
});

it('names the object type of every report so the editor can pair it with its fields', function (): void {
    ($this->report)('single-report', $this->companies);

    expect(($this->payload)()['reportOptions'][0]['object_type_id'])->toBe($this->companies);
});

it('offers a grouped report neither restriction fields nor period fields', function (): void {
    ($this->report)('grouped-report', $this->companies, ['group_by_field_key' => 'stage']);
    ($this->field)($this->companies, 'closed_on', FieldType::Date);

    $payload = ($this->payload)();

    expect($payload['scopeFieldsByReport'])->toBe([])
        ->and($payload['periodFieldsByReport'])->toBe([]);
});

it('offers the system owner and team fields as restriction fields next to the supported own fields', function (): void {
    $report = ($this->report)('single-report', $this->companies);

    ($this->field)($this->companies, 'pipeline', FieldType::SingleSelect);
    ($this->field)($this->companies, 'tags', FieldType::MultiSelect);
    ($this->field)($this->companies, 'linked', FieldType::RelationHasMany);

    $keys = array_column(($this->payload)()['scopeFieldsByReport'][(string) $report->getKey()], 'value');

    expect($keys)->toContain(SystemFilterField::Owner->value)
        ->and($keys)->toContain(SystemFilterField::Team->value)
        ->and($keys)->toContain('pipeline')
        ->and($keys)->not->toContain('tags')
        ->and($keys)->not->toContain('linked');
});

it('offers only the filterable unencrypted date fields of the reported object type as period fields', function (): void {
    $report = ($this->report)('single-report', $this->companies);

    ($this->field)($this->companies, 'closed_on', FieldType::Date);
    ($this->field)($this->companies, 'closed_at', FieldType::DateTime);
    ($this->field)($this->companies, 'sealed_on', FieldType::Date, ['is_encrypted' => true]);
    ($this->field)($this->companies, 'archived_on', FieldType::Date, ['is_filterable' => false]);
    ($this->field)($this->deals, 'signed_on', FieldType::Date);

    $keys = array_column(($this->payload)()['periodFieldsByReport'][(string) $report->getKey()], 'value');

    expect($keys)->toBe(['closed_on']);
});

it('hands a report without a date field an empty list instead of a missing key', function (): void {
    $report = ($this->report)('single-report', $this->companies);

    ($this->field)($this->companies, 'pipeline', FieldType::SingleSelect);

    $payload = ($this->payload)();

    expect($payload['periodFieldsByReport'])->toHaveKey((string) $report->getKey())
        ->and($payload['periodFieldsByReport'][(string) $report->getKey()])->toBe([]);
});

it('never offers a field of another object type as a restriction field', function (): void {
    $report = ($this->report)('single-report', $this->companies);

    ($this->field)($this->deals, 'pipeline', FieldType::SingleSelect);

    $keys = array_column(($this->payload)()['scopeFieldsByReport'][(string) $report->getKey()], 'value');

    expect($keys)->not->toContain('pipeline')
        ->and($keys)->toContain(SystemFilterField::Owner->value)
        ->and($keys)->toContain(SystemFilterField::Team->value);
});

it('offers the people and the teams of the tenant instead of a free text identifier field', function (): void {
    ($this->report)('single-report', $this->companies);

    $payload = ($this->payload)();

    expect($payload['userOptions'][0]['avatar'])->toBe(['name' => 'Dana Holder'])
        ->and($payload['userOptions'][0]['description'])->toBe('dana@example.test')
        ->and($payload['teamOptions'])->toBe([['value' => ModelStub::ulid('sales-team'), 'label' => 'Sales']]);
});

it('offers every scope, period and direction the enums define', function (): void {
    ($this->report)('single-report', $this->companies);

    $payload = ($this->payload)();

    expect(array_column($payload['scopeTypeOptions'], 'value'))->toBe(['user', 'team', 'tenant'])
        ->and(array_column($payload['periodTypeOptions'], 'value'))->toBe(['month', 'quarter', 'year'])
        ->and(array_column($payload['directionOptions'], 'value'))->toHaveCount(2);
});
