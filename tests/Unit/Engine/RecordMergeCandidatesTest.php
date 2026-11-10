<?php

declare(strict_types=1);

use App\Actions\Engine\MergeRecordsAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\Enums\CustomFields\FieldType;
use App\Http\Controllers\Engine\RecordMergeController;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Engine\MergePlanner;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('merge-candidates-object-type');
    $this->visibleId = ModelStub::ulid('merge-candidates-visible');
    $this->hiddenId = ModelStub::ulid('merge-candidates-hidden');

    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'merge-candidates-actor'));
    GateSpy::allowing('merge');

    $fields = (new Collection(['email', 'name']))->mapWithKeys(fn (string $key): array => [$key => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("merge-candidates-field-{$key}"),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => FieldType::TextShort->value,
    ])]);

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [$this->objectTypeId => $fields]));

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('merge-candidates-record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'data' => ['email' => 'dup@example.test', 'name' => 'Dup GmbH'],
    ]);

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "object_types"') => [[
            'id' => $this->objectTypeId,
            'tenant_id' => $this->tenantId,
            'dedup_keys' => json_encode([['email'], ['name']]),
        ]],
        str_contains($sql, "data->>'") => [
            ['id' => $this->visibleId, 'tenant_id' => $this->tenantId, 'object_type_id' => $this->objectTypeId],
            ['id' => $this->hiddenId, 'tenant_id' => $this->tenantId, 'object_type_id' => $this->objectTypeId],
        ],
        str_contains($sql, '"custom_records"."id" in') => [['id' => $this->visibleId]],
        default => [],
    });

    $this->controller = new RecordMergeController(
        Mockery::mock(MergePlanner::class),
        Mockery::mock(MergeRecordsAction::class),
        Mockery::mock(UndoRecordMergeAction::class),
    );

    /** @var callable():array<string, mixed> */
    $this->candidates = function (): array {
        $request = Request::create('/probe');
        $actor = $this->actor;
        $request->setUserResolver(static fn (): User => $actor);

        /** @var array{data: array<string, mixed>} $payload */
        $payload = $this->controller->candidates($request, $this->record)->getData(true);

        return $payload['data'];
    };

    /** @var callable():list<string> */
    $this->duplicateLookups = fn (): array => array_values(array_map(
        static fn (array $query): string => $query['sql'],
        array_filter($this->connection->queries, static fn (array $query): bool => str_contains($query['sql'], "data->>'")),
    ));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('suggests only duplicates the acting user may see under row access, never a hidden record id', function (): void {
    new FakeFieldVisibilityResolver;

    expect(($this->candidates)()['suggested_ids'])->toBe([$this->visibleId]);
});

it('narrows the duplicate suggestions through the scoped record query', function (): void {
    new FakeFieldVisibilityResolver;

    ($this->candidates)();

    $narrowing = array_values(array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], '"custom_records"."id" in'),
    ));

    expect($narrowing)->toHaveCount(1)
        ->and($narrowing[0]['sql'])->toContain('"custom_records"."tenant_id" = ?')
        ->and($narrowing[0]['bindings'])->toContain($this->visibleId, $this->hiddenId);
});

it('never matches duplicates over a field the acting user may not read', function (): void {
    new FakeFieldVisibilityResolver(['email']);

    ($this->candidates)();

    $lookups = ($this->duplicateLookups)();

    expect($lookups)->toHaveCount(1)
        ->and($lookups[0])->toContain("data->>'name'")
        ->and($lookups[0])->not->toContain("data->>'email'");
});

it('suggests nothing when every dedup field is unreadable for the acting user', function (): void {
    new FakeFieldVisibilityResolver(['email', 'name']);

    expect(($this->candidates)()['suggested_ids'])->toBe([])
        ->and(($this->duplicateLookups)())->toBe([]);
});
