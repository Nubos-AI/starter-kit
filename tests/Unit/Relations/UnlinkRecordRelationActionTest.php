<?php

declare(strict_types=1);

use App\Actions\Engine\ReparentRecordAction;
use App\Actions\Engine\UnlinkRecordRelationAction;
use App\Actions\Engine\UnlinkRecordsAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\RecordRelationResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
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

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->unlinkRecords = Mockery::mock(UnlinkRecordsAction::class);
    $this->reparent = Mockery::mock(ReparentRecordAction::class);
    $this->resolver = Mockery::mock(RecordRelationResolver::class);

    $this->unlink = new UnlinkRecordRelationAction($this->unlinkRecords, $this->reparent, $this->resolver);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts a link only when it touches the record it was asked about', function (): void {
    $linkId = ModelStub::ulid('link');

    $this->unlinkRecords->shouldNotReceive('execute');
    $this->reparent->shouldNotReceive('execute');

    $shape = QueryShape::attemptedBy(fn () => $this->unlink->execute($this->user, $this->record, $linkId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('record_links'))->toBeTrue()
        ->and($shape->isScopedToTenant('record_links', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('record_links', $linkId))->toBeTrue()
        ->and($shape->sql)->toContain('"from_record_id" = ? or "to_record_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->record->getKey());
});
