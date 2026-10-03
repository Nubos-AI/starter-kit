<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(string, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectType->getKey(),
            'key' => $key,
            'field_type' => FieldType::TextShort,
            'is_default_column' => true,
            'is_encrypted' => false,
            ...$overrides,
        ],
    );

    /** @var callable(list<FieldDefinition>, list<string>):RecordTitleResolver */
    $this->resolver = function (array $fields, array $readable): RecordTitleResolver {
        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('fields')->andReturn(
            (new Collection($fields))->keyBy(static fn (FieldDefinition $field): string => $field->key),
        );
        app()->instance(ObjectTypeRegistry::class, $registry);

        $visibility = Mockery::mock(FieldVisibilityResolver::class);
        $visibility->shouldReceive('readableFieldKeys')->andReturn($readable);
        app()->instance(FieldVisibilityResolver::class, $visibility);

        app()->forgetInstance(RecordTitleResolver::class);

        return RecordTitleResolver::forRequest();
    };

    /** @var callable(array<string, mixed>, ?string):CustomRecord */
    $this->record = fn (array $data, ?string $recordNumber = null): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'id' => ModelStub::ulid('company-record'),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectType->getKey(),
            'record_number' => $recordNumber,
            'data' => $data,
        ],
        ['objectType' => $this->objectType],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(ObjectTypeRegistry::class);
    app()->forgetInstance(FieldVisibilityResolver::class);
    app()->forgetInstance(RecordTitleResolver::class);
});

it('takes the title from the first default column the registry hands over', function (): void {
    $resolver = ($this->resolver)(
        [($this->field)('company_name'), ($this->field)('legal_form')],
        ['company_name', 'legal_form'],
    );

    expect($resolver->titleFor($this->user, ($this->record)([
        'company_name' => 'Acme GmbH',
        'legal_form' => 'GmbH',
    ], 'C-1')))->toBe('Acme GmbH');
});

it('prefers the name field over any other default column', function (): void {
    $resolver = ($this->resolver)(
        [($this->field)('legal_form'), ($this->field)('name')],
        ['legal_form', 'name'],
    );

    expect($resolver->titleFor($this->user, ($this->record)([
        'legal_form' => 'GmbH',
        'name' => 'Acme GmbH',
    ], 'C-1')))->toBe('Acme GmbH');
});

it('never lets an encrypted column become the title', function (): void {
    $resolver = ($this->resolver)(
        [($this->field)('tax_id', ['is_encrypted' => true]), ($this->field)('company_name')],
        ['tax_id', 'company_name'],
    );

    expect($resolver->titleFor($this->user, ($this->record)([
        'tax_id' => 'SECRET-TAX-ID',
        'company_name' => 'Acme GmbH',
    ], 'C-1')))->toBe('Acme GmbH');
});

it('falls back to the record number for a column the actor may not read', function (): void {
    $resolver = ($this->resolver)([($this->field)('company_name')], []);

    expect($resolver->titleFor($this->user, ($this->record)(['company_name' => 'Acme GmbH'], 'C-1')))
        ->toBe('C-1');
});

it('skips a column that is no default column at all', function (): void {
    $resolver = ($this->resolver)(
        [($this->field)('internal_note', ['is_default_column' => false]), ($this->field)('company_name')],
        ['internal_note', 'company_name'],
    );

    expect($resolver->titleFor($this->user, ($this->record)([
        'internal_note' => 'Intern',
        'company_name' => 'Acme GmbH',
    ], 'C-1')))->toBe('Acme GmbH');
});

it('falls back from an empty title value to the record number and then to the identifier', function (): void {
    $resolver = ($this->resolver)([($this->field)('company_name')], ['company_name']);

    $numbered = ($this->record)(['company_name' => ''], 'C-1');
    $bare = ($this->record)(['company_name' => null], null);

    expect($resolver->titleFor($this->user, $numbered))->toBe('C-1')
        ->and($resolver->titleFor($this->user, $bare))->toBe((string) $bare->getKey());
});

it('reads the raw title key without asking what the actor may see', function (): void {
    $resolver = ($this->resolver)(
        [($this->field)('tax_id', ['is_encrypted' => true]), ($this->field)('company_name')],
        [],
    );

    expect($resolver->rawTitleKey((string) $this->objectType->getKey()))->toBe('company_name');
});

it('asks the registry once per actor and object type instead of on every record', function (): void {
    $registry = Mockery::mock(ObjectTypeRegistry::class);
    $registry->shouldReceive('fields')
        ->once()
        ->andReturn(new Collection(['company_name' => ($this->field)('company_name')]));
    app()->instance(ObjectTypeRegistry::class, $registry);

    $visibility = Mockery::mock(FieldVisibilityResolver::class);
    $visibility->shouldReceive('readableFieldKeys')->once()->andReturn(['company_name']);
    app()->instance(FieldVisibilityResolver::class, $visibility);

    app()->forgetInstance(RecordTitleResolver::class);
    $resolver = RecordTitleResolver::forRequest();

    expect($resolver->titleFor($this->user, ($this->record)(['company_name' => 'Acme GmbH'])))->toBe('Acme GmbH')
        ->and($resolver->titleFor($this->user, ($this->record)(['company_name' => 'Globex AG'])))->toBe('Globex AG');
});

it('reports no title key at all when the object type declares no usable column', function (): void {
    $resolver = ($this->resolver)([], []);

    expect($resolver->titleKey($this->user, (string) $this->objectType->getKey()))->toBeNull()
        ->and($resolver->rawTitle(($this->record)([], 'C-9'), null))->toBe('C-9');
});
