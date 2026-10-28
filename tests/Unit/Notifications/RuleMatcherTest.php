<?php

declare(strict_types=1);

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Support\Notifications\RuleMatcher;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('rule-matcher-tenant');
    $this->objectTypeId = ModelStub::ulid('rule-matcher-type');
    $this->matcher = app(RuleMatcher::class);

    $this->ruleWith = fn (array $attributes = []): NotificationRule => ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('rule-matcher-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'trigger_type' => RuleTriggerType::FieldChange->value,
        'segment_id' => null,
        'filter_definition' => null,
        'config' => null,
        ...$attributes,
    ]);

    $this->tree = [
        'combinator' => 'and',
        'conditions' => [['field' => 'stage', 'operator' => 'equals', 'value' => 'won']],
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('scopes a rule without a segment and without a filter to its object type inside the tenant', function (): void {
    $shape = QueryShape::of($this->matcher->scopeQuery(($this->ruleWith)()));

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('reads the segment of a rule inside the bound tenant', function (): void {
    $segmentId = ModelStub::ulid('rule-matcher-segment');

    $attempt = QueryShape::attemptedBy(fn (): Builder => $this->matcher->scopeQuery(($this->ruleWith)(['segment_id' => $segmentId])));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('segments'))->toBeTrue()
        ->and($attempt?->hasBinding($segmentId))->toBeTrue()
        ->and($attempt?->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});

it('lets the segment of a rule win over the filter the rule carries itself', function (): void {
    $segmentId = ModelStub::ulid('rule-matcher-segment');

    $attempt = QueryShape::attemptedBy(fn (): Builder => $this->matcher->scopeQuery(($this->ruleWith)([
        'segment_id' => $segmentId,
        'filter_definition' => $this->tree,
    ])));

    expect($attempt?->targets('segments'))->toBeTrue()
        ->and($attempt?->targets('object_types'))->toBeFalse();
});

it('reads the object type of a rule before it compiles the filter the rule carries', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): Builder => $this->matcher->scopeQuery(($this->ruleWith)([
        'filter_definition' => $this->tree,
    ])));

    expect($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->isKeyedTo('object_types', $this->objectTypeId))->toBeTrue()
        ->and($attempt?->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});

it('compiles nothing at all for an empty filter definition', function (): void {
    expect(QueryShape::attemptedBy(fn (): Builder => $this->matcher->scopeQuery(($this->ruleWith)(['filter_definition' => []]))))
        ->toBeNull();
});

it('fires a creation rule on the first version of a record only', function (): void {
    $rule = ($this->ruleWith)(['trigger_type' => RuleTriggerType::Creation->value]);

    expect($this->matcher->matchesEvent($rule, [], 1))->toBeTrue()
        ->and($this->matcher->matchesEvent($rule, [], 2))->toBeFalse();
});

it('fires an assignment rule only when the owner changed', function (): void {
    $rule = ($this->ruleWith)(['trigger_type' => RuleTriggerType::Assignment->value]);

    expect($this->matcher->matchesEvent($rule, ['owner_id'], 2))->toBeTrue()
        ->and($this->matcher->matchesEvent($rule, ['stage'], 2))->toBeFalse();
});

it('fires a field change rule only for the fields the rule watches', function (): void {
    $rule = ($this->ruleWith)([
        'trigger_type' => RuleTriggerType::FieldChange->value,
        'config' => ['watched_field_keys' => ['stage', 'amount']],
    ]);

    expect($this->matcher->matchesEvent($rule, ['amount'], 2))->toBeTrue()
        ->and($this->matcher->matchesEvent($rule, ['owner_id'], 2))->toBeFalse();
});

it('fires no field change rule that watches nothing or watches nonsense', function (): void {
    expect($this->matcher->matchesEvent(($this->ruleWith)(['config' => ['watched_field_keys' => []]]), ['stage'], 2))->toBeFalse()
        ->and($this->matcher->matchesEvent(($this->ruleWith)(['config' => ['watched_field_keys' => 'stage']]), ['stage'], 2))->toBeFalse()
        ->and($this->matcher->matchesEvent(($this->ruleWith)(['config' => null]), ['stage'], 2))->toBeFalse();
});

it('never fires a date based rule on a record event', function (): void {
    $rule = ($this->ruleWith)(['trigger_type' => RuleTriggerType::DateBased->value]);

    expect($this->matcher->matchesEvent($rule, ['owner_id', 'stage'], 1))->toBeFalse();
});

it('keeps a query that was never scoped by a rule out of reach of another tenant', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of($this->matcher->scopeQuery(($this->ruleWith)()))->blocksEveryRow())->toBeTrue();
});

it('is the query a rule narrows down, not a fresh record query', function (): void {
    $query = $this->matcher->scopeQuery(($this->ruleWith)());

    expect($query->getModel())->toBeInstanceOf(CustomRecord::class);
});
