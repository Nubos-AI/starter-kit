<?php

declare(strict_types=1);

use App\DTOs\Engine\MergeFieldPlan;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeRequestData;
use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeValueOrigin;
use App\Http\Resources\Engine\MergePlanResource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\MergePlanner;
use App\Support\Engine\MergePreflight;
use App\Support\Engine\MergeRuleResolver;
use App\Support\Engine\MergeStrategyRegistry;
use App\Support\Engine\MergeTransferCounter;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('merge-access-object-type');

    AccessContext::actAs(AccessContext::user($this->tenant, [], 'merge-access-actor'));

    $field = fn (string $key, FieldType $type, int $position): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("merge-access-field-{$key}"),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type->value,
        'list_position' => $position,
        'is_unique' => false,
        'is_encrypted' => false,
        'is_translatable' => false,
    ]);

    $lookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [
        $field('score', FieldType::Rollup, 4),
        $field('locked', FieldType::TextShort, 3),
        $field('secret', FieldType::TextShort, 2),
        $field('title', FieldType::TextShort, 1),
    ]);

    $record = fn (string $seed, array $data): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid("merge-access-{$seed}"),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'version' => 1,
        'data' => $data,
    ]);

    $this->target = $record('target', ['title' => 'Ziel', 'secret' => 'T-geheim', 'locked' => null, 'score' => 3]);
    $this->source = $record('source', ['title' => 'Quelle', 'secret' => 'S-geheim', 'locked' => 'S-gesperrt', 'score' => 5]);

    $rules = Mockery::mock(MergeRuleResolver::class);
    $rules->shouldReceive('resolve')->andReturn(new MergeRuleDecision(MergeRuleMode::Allow));

    $preflight = Mockery::mock(MergePreflight::class);
    $preflight->shouldReceive('check')->andReturn([]);

    $transfers = Mockery::mock(MergeTransferCounter::class);
    $transfers->shouldReceive('count')->andReturn(0);

    $this->planner = new MergePlanner(
        $rules,
        $preflight,
        app(MergeStrategyRegistry::class),
        $transfers,
        app(TranslatableValueResolver::class),
        $lookup,
    );

    $this->plan = fn (): MergePlanData => $this->planner->plan(
        $this->target,
        $this->source,
        new MergeRequestData(
            (string) $this->target->getKey(),
            (string) $this->source->getKey(),
            overrides: ['title' => MergeValueOrigin::Source->value, 'locked' => MergeValueOrigin::Source->value],
        ),
    );

    new FakeFieldVisibilityResolver(['secret'], ['locked']);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
    Mockery::close();
});

it('leaves a field the actor may not read out of the merge preview entirely', function (): void {
    $plan = ($this->plan)();
    $payload = json_encode((new MergePlanResource($plan))->resolve(Request::create('/preview')));

    expect(array_map(static fn (MergeFieldPlan $field): string => $field->key, $plan->fields))
        ->toBe(['title', 'locked', 'score'])
        ->and($payload)->not->toContain('secret')
        ->and($payload)->not->toContain('T-geheim')
        ->and($payload)->not->toContain('S-geheim');
});

it('keeps the target value of a field the actor may not write even when the source or an override would change it', function (): void {
    $locked = collect(($this->plan)()->fields)->firstWhere('key', 'locked');

    expect($locked->resultValue)->toBeNull()
        ->and($locked->origin)->toBe(MergeValueOrigin::Target)
        ->and($locked->requiresDecision)->toBeFalse()
        ->and($locked->isOverridden)->toBeFalse();
});

it('keeps the target value of a derived field because the engine recomputes it', function (): void {
    $score = collect(($this->plan)()->fields)->firstWhere('key', 'score');

    expect($score->resultValue)->toBe(3)
        ->and($score->origin)->toBe(MergeValueOrigin::Target);
});

it('hands the update only the fields whose value the merge actually changes', function (): void {
    expect(($this->plan)()->resultingData())->toBe(['title' => 'Quelle']);
});
