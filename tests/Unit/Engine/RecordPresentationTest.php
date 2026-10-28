<?php

declare(strict_types=1);

use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordPresenter;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Modules\RecordExtensions;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant);
    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('presented-record'),
        'object_type_id' => ModelStub::ulid('presented-type'),
        'tenant_id' => $this->tenant->getKey(),
        'record_number' => 'C-1',
        'owner_id' => null,
        'external_reference_id' => null,
        'created_at' => null,
        'updated_at' => null,
        'merged_into_record_id' => null,
        'merged_at' => null,
        'version' => 1,
        'data' => ['name' => 'Company', 'private_note' => 'Hidden'],
    ]);
    $this->titles = Mockery::mock(RecordTitleResolver::class);
    $this->visibility = Mockery::mock(FieldVisibilityResolver::class);
    $this->extensions = Mockery::mock(RecordExtensions::class);
    $this->presenter = new RecordPresenter($this->titles, $this->visibility, $this->extensions);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('serializes through explicit dependencies while preserving field redaction and module facets', function (): void {
    $this->titles->shouldReceive('titleFor')->once()->with($this->viewer, $this->record)->andReturn('Company');
    $this->visibility->shouldReceive('forbiddenReadFieldKeys')->once()
        ->with($this->viewer, $this->record->object_type_id)->andReturn(['private_note']);
    $this->extensions->shouldReceive('resource')->once()->with($this->record)
        ->andReturn(['stageId' => 'qualified', 'ownerId' => 'cannot-override-spine']);
    $request = Request::create('/records');
    $request->setUserResolver(fn () => $this->viewer);

    app()->instance(RecordPresenter::class, $this->presenter);
    $payload = (new RecordResource($this->record))->resolve($request);

    expect($payload['title'])->toBe('Company')
        ->and($payload['data'])->toBe(['name' => 'Company'])
        ->and($payload['stageId'])->toBe('qualified')
        ->and($payload['ownerId'])->toBeNull()
        ->and($this->record->data)->toHaveKey('private_note', 'Hidden');
});

it('retains the existing anonymous presentation contract without looking up a viewer', function (): void {
    $this->titles->shouldNotReceive('titleFor');
    $this->visibility->shouldNotReceive('forbiddenReadFieldKeys');

    expect($this->presenter->title($this->record, null))->toBe('C-1')
        ->and($this->presenter->visibleData($this->record, null))->toBe($this->record->data);
});

it('supports Laravel resource collections with numeric and string keys', function (): void {
    app()->instance(RecordPresenter::class, $this->presenter);
    $this->extensions->shouldReceive('resource')->twice()->with($this->record)->andReturn([]);
    $this->titles->shouldNotReceive('titleFor');
    $this->visibility->shouldNotReceive('forbiddenReadFieldKeys');
    $request = Request::create('/records');

    foreach ([[0 => $this->record], ['record' => $this->record]] as $records) {
        $payload = RecordResource::collection(collect($records))->resolve($request);

        expect(array_values($payload)[0]['title'])->toBe('C-1');
    }
});
