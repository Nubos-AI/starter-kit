<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Models\ObjectType;
use App\Support\Engine\MergeRuleEditorOptions;
use App\Support\Engine\MergeRuleValidator;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('merge-crud-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'key' => 'deals',
    ]);

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Standard',
        'mode' => MergeRuleMode::Allow->value,
        'position' => 10,
        'is_active' => true,
        'deny_reason' => null,
        'condition' => null,
        'field_strategies' => [],
        'transfer_policy' => [],
        'options' => [],
        ...$overrides,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a rule that forbids merging without naming a reason', function (): void {
    expect(fn (): mixed => app(MergeRuleValidator::class)->assertValid($this->objectType, ($this->payload)([
        'mode' => MergeRuleMode::Deny->value,
        'deny_reason' => null,
    ]), null))->toThrow(ValidationException::class);
});

it('refuses an option key the catalogue does not know', function (): void {
    expect(fn (): mixed => app(MergeRuleValidator::class)->assertValid($this->objectType, ($this->payload)([
        'options' => ['grants_everything' => true],
    ]), null))->toThrow(ValidationException::class);
});

it('refuses to move the audit trail away from the source record', function (): void {
    expect(fn (): mixed => app(MergeRuleValidator::class)->assertValid($this->objectType, ($this->payload)([
        'transfer_policy' => [MergeTransferCategory::Audit->value => MergeTransferPolicy::Move->value],
    ]), null))->toThrow(ValidationException::class);
});

it('refuses a transfer category that does not exist', function (): void {
    expect(fn (): mixed => app(MergeRuleValidator::class)->assertValid($this->objectType, ($this->payload)([
        'transfer_policy' => ['invented_category' => MergeTransferPolicy::Move->value],
    ]), null))->toThrow(ValidationException::class);
});

it('drops the allow only configuration when a rule forbids merging', function (): void {
    $normalized = app(MergeRuleValidator::class)->normalize(($this->payload)([
        'mode' => MergeRuleMode::Deny->value,
        'deny_reason' => 'Gesperrt.',
        'field_strategies' => ['stage' => MergeFieldStrategy::PreferNonEmpty->value],
        'transfer_policy' => [MergeTransferCategory::Links->value => MergeTransferPolicy::Move->value],
    ]));

    expect($normalized['field_strategies'])->toBe([])
        ->and($normalized['transfer_policy'])->toBe([])
        ->and($normalized['deny_reason'])->toBe('Gesperrt.');
});

it('drops the deny reason of a rule that allows merging', function (): void {
    $normalized = app(MergeRuleValidator::class)->normalize(($this->payload)([
        'deny_reason' => 'Uebrig geblieben.',
        'field_strategies' => ['stage' => MergeFieldStrategy::PreferNonEmpty->value],
    ]));

    expect($normalized['deny_reason'])->toBeNull()
        ->and($normalized['field_strategies'])->toBe(['stage' => MergeFieldStrategy::PreferNonEmpty->value]);
});

it('offers the audit category without a way to move it and keeps the link default', function (): void {
    $categories = collect(app(MergeRuleEditorOptions::class)->transferCategories())->keyBy('value');

    expect($categories[MergeTransferCategory::Audit->value]['policies'])
        ->toBe([MergeTransferPolicy::Keep->value])
        ->and($categories[MergeTransferCategory::Timeline->value]['policies'])
        ->toBe([MergeTransferPolicy::Keep->value, MergeTransferPolicy::Discard->value])
        ->and($categories[MergeTransferCategory::Links->value]['default_policy'])
        ->toBe(MergeTransferPolicy::Move->value)
        ->and($categories)->toHaveCount(count(MergeTransferCategory::cases()));
});

it('offers per field type only the strategies that type can carry', function (): void {
    $number = array_map(
        static fn (MergeFieldStrategy $strategy): string => $strategy->value,
        MergeFieldStrategy::supportedBy(FieldType::Number),
    );

    $text = array_map(
        static fn (MergeFieldStrategy $strategy): string => $strategy->value,
        MergeFieldStrategy::supportedBy(FieldType::TextLong),
    );

    expect($number)->toContain(MergeFieldStrategy::Sum->value)
        ->and($number)->not->toContain(MergeFieldStrategy::Concatenate->value)
        ->and($number)->not->toContain(MergeFieldStrategy::Union->value)
        ->and($text)->toContain(MergeFieldStrategy::Concatenate->value)
        ->and($text)->not->toContain(MergeFieldStrategy::Sum->value);
});

it('lets only an object type manager reach the merge rule endpoints', function (string $route): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware('permission:object-types.update'))->toBeTrue()
        ->and(RouteShape::named($route)->hasDeclaredMiddleware('capability:merge'))->toBeTrue();
})->with([
    'engine.object-types.merge-rules.create',
    'engine.object-types.merge-rules.edit',
    'engine.object-types.merge-rules.store',
    'engine.object-types.merge-rules.update',
    'engine.object-types.merge-rules.destroy',
    'engine.object-types.merge-rules.bulkDestroy',
]);

it('opens the merge rule list to a viewer but never to an anonymous caller', function (): void {
    $route = RouteShape::named('engine.object-types.merge-rules.index');

    expect($route->hasDeclaredMiddleware('permission:object-types.view'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('permission:object-types.update'))->toBeFalse()
        ->and($route->resolvedMiddleware())->toContain(Authenticate::class);
});
