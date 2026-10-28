<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\DefaultValueResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->resolver = app(DefaultValueResolver::class);

    /** @var callable(string, FieldType, array<string, mixed>|null):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, ?array $default): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('default-field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('default-object-type'),
            'key' => $key,
            'field_type' => $type,
            'default_value' => $default,
        ],
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
    Auth::forgetGuards();
    AccessContext::forgetTenant();
});

it('fills an absent field with its static default', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [($this->field)('category', FieldType::TextShort, ['kind' => 'static', 'value' => 'lead'])],
        [],
    );

    expect($data)->toBe(['category' => 'lead']);
});

it('resolves today as a date and now as a timestamp', function (): void {
    Carbon::setTestNow('2026-06-28 14:30:00');

    $data = $this->resolver->applyStaticDefaults(
        [
            ($this->field)('opened_on', FieldType::Date, ['kind' => 'dynamic', 'source' => 'today']),
            ($this->field)('opened_at', FieldType::DateTime, ['kind' => 'dynamic', 'source' => 'now']),
        ],
        [],
    );

    expect($data)->toBe([
        'opened_on' => '2026-06-28',
        'opened_at' => '2026-06-28 14:30:00',
    ]);
});

it('writes the acting user into a current user default', function (): void {
    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $data = $this->resolver->applyStaticDefaults(
        [($this->field)('created_by', FieldType::TextShort, ['kind' => 'dynamic', 'source' => 'current_user'])],
        [],
    );

    expect($data)->toBe(['created_by' => (string) $user->getKey()]);
});

it('skips the current user default when nobody is authenticated', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [($this->field)('created_by', FieldType::TextShort, ['kind' => 'dynamic', 'source' => 'current_user'])],
        [],
    );

    expect($data)->toBe([]);
});

it('ignores a default whose kind or source it does not know', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [
            ($this->field)('a', FieldType::TextShort, ['kind' => 'magic', 'value' => 'x']),
            ($this->field)('b', FieldType::TextShort, ['kind' => 'dynamic', 'source' => 'moon_phase']),
            ($this->field)('c', FieldType::TextShort, null),
        ],
        [],
    );

    expect($data)->toBe([]);
});

it('lets an explicitly supplied value win even when it is falsy', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [
            ($this->field)('amount', FieldType::Number, ['kind' => 'static', 'value' => 5]),
            ($this->field)('label', FieldType::TextShort, ['kind' => 'static', 'value' => 'fallback']),
        ],
        ['amount' => 0, 'label' => ''],
    );

    expect($data)->toBe(['amount' => 0, 'label' => '']);
});

it('fills an explicit null with the default instead of keeping the null', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [($this->field)('category', FieldType::TextShort, ['kind' => 'static', 'value' => 'lead'])],
        ['category' => null],
    );

    expect($data)->toBe(['category' => 'lead']);
});

it('reuses the reserved record number for a sequence default instead of counting again', function (): void {
    $fields = [($this->field)('reference', FieldType::TextShort, ['kind' => 'dynamic', 'source' => 'sequence'])];

    $withNumber = $this->resolver->applySequenceDefaults($fields, [], 'SEQ-0000');
    $withoutNumber = $this->resolver->applySequenceDefaults($fields, [], null);
    $provided = $this->resolver->applySequenceDefaults($fields, ['reference' => 'MANUELL'], 'SEQ-0000');

    expect($withNumber)->toBe(['reference' => 'SEQ-0000'])
        ->and($withoutNumber)->toBe([])
        ->and($provided)->toBe(['reference' => 'MANUELL']);
});

it('leaves a sequence default out of the pre validation pass', function (): void {
    $data = $this->resolver->applyStaticDefaults(
        [($this->field)('reference', FieldType::TextShort, ['kind' => 'dynamic', 'source' => 'sequence'])],
        [],
    );

    expect($data)->toBe([]);
});
