<?php

declare(strict_types=1);

use App\Http\Resources\Aging\AgingRuleResource;
use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Support\Aging\AgingRuleValidator;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('aging-module-type'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->rule = fn (array $attributes = []): AgingRule => ModelStub::make(AgingRule::class, [
        'id' => ModelStub::ulid('aging-module-rule'),
        'object_type_id' => $this->objectType->getKey(),
        'name' => 'Stalled deals',
        'clock' => 'updated_at',
        'thresholds' => json_encode([['after_days' => 7, 'color' => 'amber']]),
        'is_active' => true,
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('validates no attribute beyond its own while no module registers one', function (): void {
    config(['modules.aging.rules' => []]);

    $rules = app(AgingRuleValidator::class)->rules($this->objectType, null);

    expect(array_keys($rules))->toBe([
        'name',
        'clock',
        'clock_field_key',
        'condition',
        'thresholds',
        'thresholds.*.after_days',
        'thresholds.*.color',
        'is_active',
    ]);
});

it('validates the attributes a module registers for aging rules', function (): void {
    config(['modules.aging.rules' => ['escalates' => ['boolean']]]);

    $rules = app(AgingRuleValidator::class)->rules($this->objectType, null);

    expect($rules['escalates'])->toBe(['boolean']);
});

it('exposes no attribute beyond its own while no module registers one', function (): void {
    config(['modules.aging.attributes' => []]);

    $payload = AgingRuleResource::make(($this->rule)(['escalates' => true]))->toArray(Request::create('/'));

    expect($payload)->not->toHaveKey('escalates')
        ->and($payload)->not->toHaveKey('triggers_automation');
});

it('exposes the attributes a module registers for aging rules', function (): void {
    config(['modules.aging.attributes' => ['escalates']]);

    $payload = AgingRuleResource::make(($this->rule)(['escalates' => true]))->toArray(Request::create('/'));

    expect($payload['escalates'])->toBeTrue();
});
