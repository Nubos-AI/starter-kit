<?php

declare(strict_types=1);

use App\Actions\Engine\BulkDeleteObjectTypesAction;
use App\Actions\Engine\DeleteObjectTypeAction;
use App\Enums\Engine\StorageStrategy;
use App\Http\Controllers\Engine\ObjectTypesController;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRecordPresence;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    $this->objectTypeRow = fn (string $seed): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => $seed,
        'key' => $seed,
        'name' => ucfirst($seed),
        'is_system' => false,
        'storage_strategy' => StorageStrategy::Generic->value,
    ];

    $this->objectType = fn (string $seed): ObjectType => ModelStub::make(ObjectType::class, ($this->objectTypeRow)($seed));

    /** @var callable(list<string>, list<array<string, mixed>>): StaticQueryConnection */
    $this->install = fn (array $holdingSeeds, array $objectTypeRows = []): StaticQueryConnection => StaticQueryConnection::install(
        static function (string $sql) use ($holdingSeeds, $objectTypeRows): array {
            if (str_contains($sql, 'from "custom_records"') && str_contains($sql, 'exists')) {
                return array_map(static fn (string $seed): array => ['id' => ModelStub::ulid($seed)], $holdingSeeds);
            }

            if (str_contains($sql, 'from "object_types"') && !str_contains($sql, 'custom_records')) {
                return $objectTypeRows;
            }

            return [];
        },
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to delete an object type that still holds records and leaves it in place', function (): void {
    $connection = ($this->install)(['orders']);

    try {
        app(DeleteObjectTypeAction::class)->execute(($this->objectType)('orders'));
        $this->fail('The object type was deleted although it still holds records.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['objectType'] ?? [])
            ->toBe(['Der Objekttyp enthält noch Datensätze, auch im Papierkorb. Löschen Sie diese endgültig, bevor Sie den Objekttyp löschen.']);
    }

    expect($connection->writtenStatements)->toBe([]);
});

it('deletes an object type without any record', function (): void {
    $connection = ($this->install)([]);

    app(DeleteObjectTypeAction::class)->execute(($this->objectType)('orders'));

    expect(collect($connection->writtenStatements)->pluck('sql')->implode(' '))
        ->toContain('update "object_types" set "deleted_at"');
});

it('counts trashed records and records of every team when it decides whether a type still holds records', function (): void {
    $connection = ($this->install)([]);

    app(ObjectTypeRecordPresence::class)->holdsRecords(($this->objectType)('orders'));

    $presenceQuery = collect($connection->queries)
        ->first(static fn (array $query): bool => str_contains($query['sql'], 'custom_records'));

    expect($presenceQuery)->not->toBeNull()
        ->and($presenceQuery['sql'])->toContain('"custom_records"."object_type_id" = "object_types"."id"')
        ->and($presenceQuery['sql'])->not->toContain('custom_records"."deleted_at')
        ->and($presenceQuery['sql'])->not->toContain('team_id')
        ->and($presenceQuery['bindings'])->toContain(ModelStub::ulid('orders'));
});

it('asks once for the whole list instead of once per object type', function (): void {
    $connection = ($this->install)(['orders']);

    $holding = app(ObjectTypeRecordPresence::class)->holdingRecords(collect([
        ($this->objectType)('orders'),
        ($this->objectType)('projects'),
    ]));

    expect($holding)->toBe([ModelStub::ulid('orders') => true])
        ->and($connection->queries)->toHaveCount(1);
});

it('skips an object type that still holds records in a bulk delete', function (): void {
    AccessContext::grant('object-types.delete');

    ($this->install)(['orders'], [($this->objectTypeRow)('orders'), ($this->objectTypeRow)('projects')]);

    $deleted = app(BulkDeleteObjectTypesAction::class)->execute($this->actor, [
        'ids' => [ModelStub::ulid('orders'), ModelStub::ulid('projects')],
    ]);

    expect($deleted->modelKeys())->toBe([ModelStub::ulid('projects')]);
});

it('marks an object type with records as not deletable and names the reason in the list', function (): void {
    AccessContext::grant('object-types.delete', 'object-types.update');

    ($this->install)(['orders'], [($this->objectTypeRow)('orders'), ($this->objectTypeRow)('projects')]);

    $request = Request::create('/object-types');
    $request->setUserResolver(fn () => $this->actor);
    $request->headers->set('X-Inertia', 'true');

    $rows = collect(app(ObjectTypesController::class)->index($request)->toResponse($request)->getData(true)['props']['objectTypes'])
        ->keyBy('slug');

    expect($rows['orders']['can_delete'])->toBeFalse()
        ->and($rows['orders']['delete_reason'])->toBe('Der Objekttyp enthält noch Datensätze, auch im Papierkorb. Löschen Sie diese endgültig, bevor Sie den Objekttyp löschen.')
        ->and($rows['projects']['can_delete'])->toBeTrue()
        ->and($rows['projects']['delete_reason'])->toBeNull();
});
