<?php

declare(strict_types=1);

use App\Contracts\Modules\RecordSuppliedValuesInterface;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordPresenter;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Modules\RecordExtensions;
use App\Support\Modules\RegisteredModuleComponents;
use Illuminate\Http\Request;
use Tests\Fixtures\Modules\nubos\example\src\ExampleRecordSuppliedValues;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant);
    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('supplied-record'),
        'object_type_id' => ModelStub::ulid('supplied-type'),
        'tenant_id' => $this->tenant->getKey(),
        'record_number' => 'P-1',
        'owner_id' => null,
        'external_reference_id' => null,
        'created_at' => null,
        'updated_at' => null,
        'merged_into_record_id' => null,
        'merged_at' => null,
        'version' => 1,
        'data' => ['name' => 'Pro', 'price' => '99.00', 'tax_rate' => '7', 'cost' => '10.00'],
    ]);
    $this->coreSupplier = new class implements RecordSuppliedValuesInterface
    {
        public function suppliedValues(CustomRecord $record): array
        {
            return ['tax_rate' => '19.00'];
        }
    };
    $this->extensionsWhileRegistered = fn (array $registered): RecordExtensions => new RecordExtensions(
        new RegisteredModuleComponents(new FixtureModuleCatalog, new StaticModuleRegistry($registered)),
        suppliers: [new ExampleRecordSuppliedValues, $this->coreSupplier],
    );
    $this->titles = Mockery::mock(RecordTitleResolver::class);
    $this->visibility = Mockery::mock(FieldVisibilityResolver::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('merges the values every active module supplies for a record', function (): void {
    expect(($this->extensionsWhileRegistered)(['nubos/example'])->suppliedValues($this->record))
        ->toBe(['price' => '49.00', 'tax_rate' => '19.00']);
});

it('leaves out the values of a module that is unregistered while its code is still installed', function (): void {
    expect(($this->extensionsWhileRegistered)([])->suppliedValues($this->record))
        ->toBe(['tax_rate' => '19.00']);
});

it('presents supplied values instead of the stored ones while still redacting forbidden fields', function (): void {
    $presenter = new RecordPresenter($this->titles, $this->visibility, ($this->extensionsWhileRegistered)(['nubos/example']));
    $this->visibility->shouldReceive('forbiddenReadFieldKeys')->once()
        ->with($this->viewer, $this->record->object_type_id)->andReturn(['tax_rate']);

    expect($presenter->visibleData($this->record, $this->viewer))
        ->toBe(['name' => 'Pro', 'price' => '49.00', 'cost' => '10.00'])
        ->and($presenter->visibleData($this->record, null))
        ->toBe(['name' => 'Pro', 'price' => '49.00', 'tax_rate' => '19.00', 'cost' => '10.00'])
        ->and($this->record->data['price'])->toBe('99.00');
});

it('omits the fields a page hides from the serialized record data', function (): void {
    app()->instance(RecordPresenter::class, new RecordPresenter($this->titles, $this->visibility, ($this->extensionsWhileRegistered)(['nubos/example'])));
    $request = Request::create('/records');

    $payload = RecordResource::make($this->record)->omitting(['price', 'tax_rate'])->resolve($request);

    expect($payload['data'])->toBe(['name' => 'Pro', 'cost' => '10.00'])
        ->and($payload['suppliedKeys'])->toBe(['price', 'tax_rate']);
});

it('tells every row which of its fields another app supplies so a list keeps them read-only', function (): void {
    app()->instance(RecordPresenter::class, new RecordPresenter($this->titles, $this->visibility, ($this->extensionsWhileRegistered)([])));

    $payload = RecordResource::make($this->record)->resolve(Request::create('/records'));

    expect($payload['suppliedKeys'])->toBe(['tax_rate'])
        ->and($payload['data']['tax_rate'])->toBe('19.00');
});
