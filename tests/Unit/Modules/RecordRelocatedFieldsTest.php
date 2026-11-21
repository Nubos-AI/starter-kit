<?php

declare(strict_types=1);

use App\Contracts\Modules\RecordRelocatedFieldsInterface;
use App\Models\ObjectType;
use App\Support\Modules\RecordExtensions;
use App\Support\Modules\RegisteredModuleComponents;
use Tests\Fixtures\Modules\nubos\example\src\ExampleRecordRelocatedFields;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->productType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('relocated-type'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'products',
    ]);
    $this->companyType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('company-type'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'companies',
    ]);
    $this->coreRelocator = new class implements RecordRelocatedFieldsInterface
    {
        /** @var list<ObjectType> */
        public array $askedFor = [];

        public function relocatedFieldKeys(ObjectType $objectType): array
        {
            $this->askedFor[] = $objectType;

            return $objectType->key === 'products' ? ['margin', 'notes'] : [];
        }
    };
    $this->extensionsWhileRegistered = fn (array $registered): RecordExtensions => new RecordExtensions(
        new RegisteredModuleComponents(new FixtureModuleCatalog, new StaticModuleRegistry($registered)),
        relocators: [new ExampleRecordRelocatedFields, $this->coreRelocator],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('collects every field an active module edits in its own section of the record page, each key once', function (): void {
    expect(($this->extensionsWhileRegistered)(['nubos/example'])->relocatedFieldKeys($this->productType))
        ->toBe(['cost', 'margin', 'notes'])
        ->and($this->coreRelocator->askedFor)->toBe([$this->productType]);
});

it('keeps the fields of an unregistered module in the generic field groups', function (): void {
    expect(($this->extensionsWhileRegistered)([])->relocatedFieldKeys($this->productType))
        ->toBe(['margin', 'notes']);
});

it('relocates nothing on an object type no module takes fields of', function (): void {
    expect(($this->extensionsWhileRegistered)([])->relocatedFieldKeys($this->companyType))
        ->toBe([]);
});
