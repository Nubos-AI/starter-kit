<?php

declare(strict_types=1);

use App\Enums\Engine\AgingClock;
use App\Enums\Engine\AgingThresholdColor;
use App\Enums\Engine\SystemFilterField;
use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Support\Aging\AgingRuleValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('aging-validated-type'),
        'tenant_id' => $this->tenant->getKey(),
    ]);
    $this->validator = app(AgingRuleValidator::class);

    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Stalled deals',
        'clock' => AgingClock::UpdatedAt->value,
        'clock_field_key' => null,
        'thresholds' => [['after_days' => 7, 'color' => AgingThresholdColor::Amber->value]],
        'is_active' => false,
        ...$overrides,
    ];

    $this->messageKeysOf = static function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses thresholds that do not climb strictly', function (): void {
    $payload = ($this->payload)(['thresholds' => [
        ['after_days' => 7, 'color' => AgingThresholdColor::Amber->value],
        ['after_days' => 7, 'color' => AgingThresholdColor::Red->value],
    ]]);

    expect(($this->messageKeysOf)(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBe(['thresholds']);
});

it('accepts thresholds that climb', function (): void {
    $payload = ($this->payload)(['thresholds' => [
        ['after_days' => 7, 'color' => AgingThresholdColor::Amber->value],
        ['after_days' => 14, 'color' => AgingThresholdColor::Red->value],
    ]]);

    expect(($this->messageKeysOf)(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBe([]);
});

it('refuses a measured field on a clock that does not measure a field', function (): void {
    $payload = ($this->payload)(['clock_field_key' => 'closed_on']);

    expect(($this->messageKeysOf)(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBe(['clock_field_key']);
});

it('refuses a field clock that names no field at all', function (): void {
    $payload = ($this->payload)(['clock' => AgingClock::Field->value, 'clock_field_key' => null]);

    expect(($this->messageKeysOf)(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBe(['clock_field_key']);
});

it('looks the measured field up inside the addressed object type only', function (): void {
    $payload = ($this->payload)(['clock' => AgingClock::Field->value, 'clock_field_key' => 'closed_on']);

    $attempt = QueryShape::attemptedBy(fn () => $this->validator->assertValid($this->objectType, $payload, null));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('field_definitions'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding('closed_on'))->toBeTrue();
});

it('refuses a condition that reads the aging field the rule itself produces', function (): void {
    $payload = ($this->payload)(['condition' => [
        'combinator' => 'and',
        'conditions' => [['field' => SystemFilterField::AgingAge->value, 'operator' => 'equals', 'value' => 3]],
    ]]);

    expect(($this->messageKeysOf)(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBe(['condition']);
});

it('counts the active rules of the object type before it lets another active rule through', function (): void {
    $payload = ($this->payload)(['is_active' => true]);

    $attempt = QueryShape::attemptedBy(fn () => $this->validator->assertValid($this->objectType, $payload, null));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('aging_rules'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('count(*)');
});

it('leaves an inactive rule out of the active rule limit', function (): void {
    $payload = ($this->payload)(['is_active' => false]);

    expect(QueryShape::attemptedBy(fn () => $this->validator->assertValid($this->objectType, $payload, null)))
        ->toBeNull();
});

it('keeps the rule under edit out of its own active rule count', function (): void {
    $rule = ModelStub::make(AgingRule::class, [
        'id' => ModelStub::ulid('aging-edited-rule'),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $attempt = QueryShape::attemptedBy(
        fn () => $this->validator->assertValid($this->objectType, ($this->payload)(['is_active' => true]), $rule),
    );

    expect($attempt?->sql)->toContain('"aging_rules"."id" !=')
        ->and($attempt?->hasBinding((string) $rule->getKey()))->toBeTrue();
});

it('drops a measured field key that no longer belongs to the chosen clock', function (): void {
    $normalized = $this->validator->normalize(($this->payload)([
        'clock' => AgingClock::UpdatedAt->value,
        'clock_field_key' => 'closed_on',
    ]));

    expect($normalized['clock_field_key'])->toBeNull();
});

it('casts every threshold step to an integer day count and a string colour', function (): void {
    $normalized = $this->validator->normalize(($this->payload)(['thresholds' => [
        ['after_days' => '7', 'color' => AgingThresholdColor::Amber->value],
        ['after_days' => 14.0, 'color' => AgingThresholdColor::Red->value],
    ]]));

    expect($normalized['thresholds'])->toBe([
        ['after_days' => 7, 'color' => AgingThresholdColor::Amber->value],
        ['after_days' => 14, 'color' => AgingThresholdColor::Red->value],
    ]);
});

it('keeps the rule name unique per object type and ignores soft deleted rules', function (): void {
    $rules = $this->validator->rules($this->objectType, null);

    expect((string) $rules['name'][3])
        ->toContain('unique:aging_rules,name')
        ->toContain('object_type_id,"'.$this->objectType->getKey().'"')
        ->toContain('deleted_at,"NULL"');
});
