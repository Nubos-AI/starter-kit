<?php

declare(strict_types=1);

use App\Actions\Engine\LinkRecordRelationAction;
use App\Actions\Engine\UnlinkRecordRelationAction;
use App\Enums\Engine\RelationDirection;
use App\Http\Controllers\Engine\RecordRelationsController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordRelationGroupBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Config::set('engine.relations.max_block_size', 200);

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

    $this->linkRelation = Mockery::mock(LinkRecordRelationAction::class);
    $this->unlinkRelation = Mockery::mock(UnlinkRecordRelationAction::class);
    $this->groupBuilder = Mockery::mock(RecordRelationGroupBuilder::class);

    $this->controller = new RecordRelationsController(
        $this->linkRelation,
        $this->unlinkRelation,
        $this->groupBuilder,
    );

    /** @var callable(array<string, mixed>, bool):Request */
    $this->request = function (array $payload, bool $signedIn = true): Request {
        $request = Request::create('/probe', 'GET', $payload);

        if ($signedIn) {
            $user = $this->user;
            $request->setUserResolver(static fn (): User => $user);
        }

        return $request;
    };

    /** @var callable(int, int, string|null):array<string, mixed> */
    $this->block = fn (int $startRow, int $endRow, ?string $search = null): array => [
        'relationshipTypeId' => ModelStub::ulid('carrier'),
        'direction' => 'outgoing',
        'startRow' => $startRow,
        'endRow' => $endRow,
        'search' => $search,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a block whose end row does not lie beyond its start row', function (): void {
    $this->groupBuilder->shouldNotReceive('entriesPage');

    expect(fn () => $this->controller->entries(($this->request)(($this->block)(10, 10)), $this->record))
        ->toThrow(ValidationException::class);
});

it('refuses a block for a relationship type key that is no ulid', function (): void {
    $this->groupBuilder->shouldNotReceive('entriesPage');

    $payload = [...($this->block)(0, 25), 'relationshipTypeId' => 'not-a-ulid'];

    expect(fn () => $this->controller->entries(($this->request)($payload), $this->record))
        ->toThrow(ValidationException::class);
});

it('refuses a block in a direction the relation endpoints do not know', function (): void {
    $this->groupBuilder->shouldNotReceive('entriesPage');

    $payload = [...($this->block)(0, 25), 'direction' => 'sideways'];

    expect(fn () => $this->controller->entries(($this->request)($payload), $this->record))
        ->toThrow(ValidationException::class);
});

it('hands the offset, the requested size and the search term to the group builder', function (): void {
    $this->groupBuilder->shouldReceive('entriesPage')->once()
        ->with($this->record, $this->user, ModelStub::ulid('carrier'), RelationDirection::Outgoing, 40, 25, 'acme')
        ->andReturn(['entries' => [], 'total' => 0, 'lastRow' => 0]);

    $this->controller->entries(($this->request)(($this->block)(40, 65, 'acme')), $this->record);
});

it('caps a block at the configured maximum size however many rows the client asks for', function (): void {
    Config::set('engine.relations.max_block_size', 200);

    $this->groupBuilder->shouldReceive('candidatesPage')->once()
        ->with($this->record, $this->user, ModelStub::ulid('carrier'), RelationDirection::Outgoing, 0, 200, null)
        ->andReturn(['candidates' => [], 'hasMore' => false]);

    $this->controller->candidates(($this->request)(($this->block)(0, 100000)), $this->record);
});

it('refuses a block to a request that carries no user', function (): void {
    $this->groupBuilder->shouldNotReceive('entriesPage');

    expect(fn () => $this->controller->entries(($this->request)(($this->block)(0, 25), false), $this->record))
        ->toThrow(AuthorizationException::class);
});

it('links through the action on behalf of the acting user', function (): void {
    $payload = ['relationship_type_id' => ModelStub::ulid('carrier'), 'direction' => 'outgoing', 'target_record_id' => ModelStub::ulid('partner-record')];

    $this->linkRelation->shouldReceive('execute')->once()
        ->withArgs(fn (User $actor, CustomRecord $record, array $input): bool => $actor->is($this->user)
            && $record->is($this->record)
            && $input['direction'] === 'outgoing');

    $response = $this->controller->store(($this->request)($payload), $this->record);

    expect($response->getStatusCode())->toBe(200);
});

it('unlinks the named edge through the action on behalf of the acting user', function (): void {
    $linkId = ModelStub::ulid('link');

    $this->unlinkRelation->shouldReceive('execute')->once()
        ->with(Mockery::on(fn (User $actor): bool => $actor->is($this->user)), $this->record, $linkId);

    $response = $this->controller->destroy(($this->request)([]), $this->record, $linkId);

    expect($response->getStatusCode())->toBe(200);
});
