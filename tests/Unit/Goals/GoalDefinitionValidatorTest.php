<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Models\FieldDefinition;
use App\Models\Report;
use App\Models\Team;
use App\Support\Goals\GoalDefinitionSource;
use App\Support\Goals\GoalDefinitionValidator;
use App\Support\Goals\GoalTargetTeamResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use Tests\Unit\Goals\Doubles\StaticGoalDefinitionSource;
use Tests\Unit\Goals\Doubles\StaticGoalTargetTeamResolver;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->source = new StaticGoalDefinitionSource;

    app()->instance(GoalDefinitionSource::class, $this->source);
    app()->instance(GoalTargetTeamResolver::class, new StaticGoalTargetTeamResolver);

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'Revenue',
        'group_by_field_key' => null,
        'series_field_key' => null,
    ]);

    $this->source->withReport($this->report);

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = function (string $key, FieldType $type, array $attributes = []): FieldDefinition {
        $field = ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_filterable' => true,
            'is_encrypted' => false,
            'is_translatable' => false,
            ...$attributes,
        ]);

        $this->source->withField((string) $field->object_type_id, $field);

        return $field;
    };

    /** @var callable(bool, list<Team>):StaticAuthorityUser */
    $this->actor = function (bool $escalated = false, array $teams = []): StaticAuthorityUser {
        /** @var StaticAuthorityUser $actor */
        $actor = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid('actor'),
            'tenant_id' => $this->tenant->getKey(),
        ], ['teams' => new EloquentCollection($teams)]);

        $actor->escalated = $escalated;

        $this->source->withUser((string) $actor->getKey());

        return $actor;
    };

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->input = fn (array $overrides = []): array => [
        'name' => 'Revenue goal',
        'report_id' => (string) $this->report->getKey(),
        'scope_type' => GoalScopeType::User->value,
        'period_type' => GoalPeriodType::Month->value,
        'direction' => GoalDirection::AtLeast->value,
        'target_value' => '1000',
        ...$overrides,
    ];

    /** @var callable(StaticAuthorityUser, array<string, mixed>):?ValidationException */
    $this->refusal = static function (StaticAuthorityUser $actor, array $input): ?ValidationException {
        try {
            app(GoalDefinitionValidator::class)->validate($actor, $input);
        } catch (ValidationException $exception) {
            return $exception;
        }

        return null;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(GoalDefinitionSource::class);
    app()->forgetInstance(GoalTargetTeamResolver::class);
});

it('refuses a report the actor may not view and never reveals that it exists', function (): void {
    GateSpy::allowing();

    $actor = ($this->actor)();
    $input = ($this->input)(['target_user_id' => (string) $actor->getKey()]);

    $refusal = ($this->refusal)($actor, $input);

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['report_id'])
        ->and($refusal->errors()['report_id'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.the_selected_report_does_not_exist'));
});

it('asks the gate for the view right on the very report the input names', function (): void {
    $spy = GateSpy::allowing('view');

    $actor = ($this->actor)();
    ($this->field)(SystemFilterField::Owner->value, FieldType::SingleSelect);

    app(GoalDefinitionValidator::class)->validate($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
    ]));

    expect($spy->wasAskedFor('view'))->toBeTrue()
        ->and($spy->calls[0]['arguments'][0])->toBe($this->report);
});

it('looks the report up inside the tenant of the actor and never by id alone', function (): void {
    GateSpy::allowing('view');

    $actor = ($this->actor)();
    ($this->field)(SystemFilterField::Owner->value, FieldType::SingleSelect);

    app(GoalDefinitionValidator::class)->validate($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
    ]));

    expect($this->source->reportLookups)->toBe([[
        'tenantId' => (string) $this->tenant->getKey(),
        'reportId' => (string) $this->report->getKey(),
    ]]);
});

it('refuses a report of another tenant with the same message as a missing one', function (): void {
    GateSpy::allowing('view');

    $foreign = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('foreign-report'),
        'tenant_id' => ModelStub::ulid('other-tenant'),
        'object_type_id' => $this->objectTypeId,
        'group_by_field_key' => null,
        'series_field_key' => null,
    ]);

    $this->source->withReport($foreign);

    $actor = ($this->actor)();

    $refusal = ($this->refusal)($actor, ($this->input)([
        'report_id' => (string) $foreign->getKey(),
        'target_user_id' => (string) $actor->getKey(),
    ]));

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['report_id']);
});

it('refuses a report that carries a grouping or a series because a goal needs one metric', function (): void {
    GateSpy::allowing('view');

    $grouped = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('grouped-report'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'group_by_field_key' => 'stage',
        'series_field_key' => null,
    ]);

    $series = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('series-report'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'group_by_field_key' => null,
        'series_field_key' => 'created_at',
    ]);

    $this->source->withReport($grouped)->withReport($series);

    $actor = ($this->actor)();
    $expected = __('i18n.backend.support.goals.goal_definition_validator.a_goal_requires_a_report_with_exactly_one_metric');

    $groupedRefusal = ($this->refusal)($actor, ($this->input)([
        'report_id' => (string) $grouped->getKey(),
        'target_user_id' => (string) $actor->getKey(),
    ]));

    $seriesRefusal = ($this->refusal)($actor, ($this->input)([
        'report_id' => (string) $series->getKey(),
        'target_user_id' => (string) $actor->getKey(),
    ]));

    expect($groupedRefusal?->errors()['report_id'][0])->toBe($expected)
        ->and($seriesRefusal?->errors()['report_id'][0])->toBe($expected);
});

it('refuses a personal goal on somebody else unless the actor carries escalated authority', function (): void {
    GateSpy::allowing('view');

    ($this->field)(SystemFilterField::Owner->value, FieldType::SingleSelect);

    $stranger = ModelStub::ulid('stranger');
    $this->source->withUser($stranger);

    $plain = ($this->actor)();
    $refusal = ($this->refusal)($plain, ($this->input)(['target_user_id' => $stranger]));

    $escalated = ($this->actor)(true);
    $accepted = ($this->refusal)($escalated, ($this->input)(['target_user_id' => $stranger]));

    expect($refusal?->errors()['target_user_id'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_another_person_requires_elevated_permissions'))
        ->and($accepted)->toBeNull();
});

it('refuses a personal goal on a person outside the tenant even for an escalated authority', function (): void {
    GateSpy::allowing('view');

    ($this->field)(SystemFilterField::Owner->value, FieldType::SingleSelect);

    $escalated = ($this->actor)(true);

    $refusal = ($this->refusal)($escalated, ($this->input)([
        'target_user_id' => ModelStub::ulid('foreign-user'),
    ]));

    expect($refusal?->errors()['target_user_id'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.a_personal_goal_requires_a_person_from_this_tenant'));
});

it('refuses a team goal for a team the actor does not belong to unless the actor is escalated', function (): void {
    GateSpy::allowing('view');

    ($this->field)(SystemFilterField::Team->value, FieldType::SingleSelect);

    $foreignTeam = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('other-team'),
        'tenant_id' => $this->tenant->getKey(),
        'ancestor_team_ids' => [],
    ]);

    $this->source->withTeam((string) $foreignTeam->getKey());

    $input = ($this->input)([
        'scope_type' => GoalScopeType::Team->value,
        'target_team_id' => (string) $foreignTeam->getKey(),
    ]);

    $outsider = ($this->refusal)(($this->actor)(), $input);
    $member = ($this->refusal)(($this->actor)(false, [$foreignTeam]), $input);
    $escalated = ($this->refusal)(($this->actor)(true), $input);

    expect($outsider?->errors()['target_team_id'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_a_team_you_do_not'))
        ->and($member)->toBeNull()
        ->and($escalated)->toBeNull();
});

it('refuses a team goal on a team of another tenant even for an escalated authority', function (): void {
    GateSpy::allowing('view');

    ($this->field)(SystemFilterField::Team->value, FieldType::SingleSelect);

    $refusal = ($this->refusal)(($this->actor)(true), ($this->input)([
        'scope_type' => GoalScopeType::Team->value,
        'target_team_id' => ModelStub::ulid('foreign-team'),
    ]));

    expect($refusal?->errors()['target_team_id'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.a_team_goal_requires_a_team_from_this_tenant'));
});

it('reserves a goal on the whole tenant for an escalated authority', function (): void {
    GateSpy::allowing('view');

    $input = ($this->input)(['scope_type' => GoalScopeType::Tenant->value]);

    $refusal = ($this->refusal)(($this->actor)(), $input);
    $accepted = ($this->refusal)(($this->actor)(true), $input);

    expect($refusal?->errors()['scope_type'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_the_entire_tenant_requires_elevated'))
        ->and($accepted)->toBeNull();
});

it('refuses a tenant goal that carries a restriction field instead of silently dropping it', function (): void {
    GateSpy::allowing('view');

    ($this->field)('pipeline', FieldType::SingleSelect);

    $refusal = ($this->refusal)(($this->actor)(true), ($this->input)([
        'scope_type' => GoalScopeType::Tenant->value,
        'scope_field_key' => 'pipeline',
    ]));

    expect($refusal?->errors()['scope_field_key'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.a_tenant_goal_applies_to_the_entire_tenant_and'));
});

it('refuses a restriction field that does not belong to the analysed object type', function (): void {
    GateSpy::allowing('view');

    $foreignField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('foreign-field'),
        'object_type_id' => ModelStub::ulid('deals'),
        'key' => 'pipeline',
        'field_type' => FieldType::SingleSelect,
        'is_filterable' => true,
    ]);

    $this->source->withField((string) $foreignField->object_type_id, $foreignField);

    $actor = ($this->actor)();

    $refusal = ($this->refusal)($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
        'scope_field_key' => 'pipeline',
    ]));

    expect($refusal?->errors()['scope_field_key'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.the_restricting_field_must_belong_to_the_analysed_object'));
});

it('refuses a restriction field that cannot express a set comparison', function (): void {
    GateSpy::allowing('view');

    ($this->field)('tags', FieldType::MultiSelect);
    ($this->field)('linked', FieldType::RelationHasMany);
    ($this->field)('hidden', FieldType::SingleSelect, ['is_filterable' => false]);

    $actor = ($this->actor)();
    $expected = __('i18n.backend.support.goals.goal_definition_validator.the_restricting_field_must_belong_to_the_analysed_object');

    foreach (['tags', 'linked', 'hidden'] as $key) {
        $refusal = ($this->refusal)($actor, ($this->input)([
            'target_user_id' => (string) $actor->getKey(),
            'scope_field_key' => $key,
        ]));

        expect($refusal?->errors()['scope_field_key'][0])->toBe($expected);
    }
});

it('falls back to the system owner field when a personal goal names no restriction field', function (): void {
    GateSpy::allowing('view');

    $actor = ($this->actor)();

    expect(($this->refusal)($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
    ])))->toBeNull();
});

it('accepts only a filterable unencrypted date field as the period field', function (): void {
    GateSpy::allowing('view');

    ($this->field)('closed_on', FieldType::Date);
    ($this->field)('closed_at', FieldType::DateTime);
    ($this->field)('sealed_on', FieldType::Date, ['is_encrypted' => true]);
    ($this->field)('archived_on', FieldType::Date, ['is_filterable' => false]);

    $actor = ($this->actor)();
    $expected = __('i18n.backend.support.goals.goal_definition_validator.the_period_field_must_be_a_filterable_unencrypted_date');

    /** @var callable(?string):?ValidationException */
    $probe = fn (?string $key): ?ValidationException => ($this->refusal)($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
        'period_field_key' => $key,
    ]));

    expect($probe('closed_on'))->toBeNull()
        ->and($probe(null))->toBeNull()
        ->and($probe(''))->toBeNull()
        ->and($probe('closed_at')?->errors()['period_field_key'][0])->toBe($expected)
        ->and($probe('sealed_on')?->errors()['period_field_key'][0])->toBe($expected)
        ->and($probe('archived_on')?->errors()['period_field_key'][0])->toBe($expected)
        ->and($probe('unknown_on')?->errors()['period_field_key'][0])->toBe($expected);
});

it('refuses a period field that belongs to another object type', function (): void {
    GateSpy::allowing('view');

    $foreignField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('foreign-date'),
        'object_type_id' => ModelStub::ulid('deals'),
        'key' => 'closed_on',
        'field_type' => FieldType::Date,
        'is_filterable' => true,
        'is_encrypted' => false,
    ]);

    $this->source->withField((string) $foreignField->object_type_id, $foreignField);

    $actor = ($this->actor)();

    $refusal = ($this->refusal)($actor, ($this->input)([
        'target_user_id' => (string) $actor->getKey(),
        'period_field_key' => 'closed_on',
    ]));

    expect($refusal?->errors()['period_field_key'][0])
        ->toBe(__('i18n.backend.support.goals.goal_definition_validator.the_period_field_must_be_a_filterable_unencrypted_date'));
});
