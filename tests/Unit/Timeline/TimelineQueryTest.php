<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Models\CustomRecord;
use App\Support\Timeline\TimelineQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('timeline-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->query = new TimelineQuery;

    /**
     * @param  array<string, mixed>  $arguments
     */
    $this->shapeOf = function (array $arguments = []): QueryShape {
        $shape = QueryShape::attemptedBy(fn () => $this->query->paginate(...['record' => $this->record, ...$arguments]));

        expect($shape)->not->toBeNull();

        return $shape;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('pins every page to the tenant of the record and to that record alone', function (): void {
    $shape = ($this->shapeOf)();

    expect($shape->targets('timeline_entries'))->toBeTrue()
        ->and($shape->sql)->toContain('where "tenant_id" = ? and "record_id" = ?')
        ->and($shape->bindings)->toBe([
            (string) $this->tenant->getKey(),
            (string) $this->record->getKey(),
            (string) $this->tenant->getKey(),
        ]);
});

it('keeps the tenant scope on top of the explicit tenant condition so a foreign record can match nothing', function (): void {
    $foreign = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('foreign-record'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
    ]);

    $shape = QueryShape::attemptedBy(fn () => $this->query->paginate(record: $foreign));

    expect($shape)->not->toBeNull()
        ->and($shape->isScopedToTenant('timeline_entries', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->bindings)->toBe([
            ModelStub::ulid('foreign-tenant'),
            ModelStub::ulid('foreign-record'),
            (string) $this->tenant->getKey(),
        ]);
});

it('orders newest first and breaks a tie on the occurrence moment by the key descending', function (): void {
    expect(($this->shapeOf)()->sql)->toContain('order by "occurred_at" desc, "id" desc');
});

it('asks for one row more than the page size so it knows whether a further page exists', function (): void {
    expect(($this->shapeOf)(['perPage' => 20])->sql)->toContain('limit 21')
        ->and(($this->shapeOf)(['perPage' => 5])->sql)->toContain('limit 6');
});

it('adds no source condition at all when no source key is asked for', function (): void {
    expect(($this->shapeOf)()->sql)->not->toContain('"source_key"');
});

it('filters on a set of source keys with in semantics', function (): void {
    $shape = ($this->shapeOf)(['sourceKeys' => ['note', 'reminder']]);

    expect($shape->sql)->toContain('"source_key" in (?, ?)')
        ->and($shape->hasBinding('note'))->toBeTrue()
        ->and($shape->hasBinding('reminder'))->toBeTrue();
});

it('includes both range bounds instead of excluding them', function (): void {
    $shape = ($this->shapeOf)([
        'occurredFrom' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        'occurredTo' => CarbonImmutable::parse('2026-01-31 23:59:59'),
    ]);

    expect($shape->sql)->toContain('"occurred_at" >= ?')
        ->and($shape->sql)->toContain('"occurred_at" <= ?')
        ->and($shape->bindings)->toContain('2026-01-01 00:00:00')
        ->and($shape->bindings)->toContain('2026-01-31 23:59:59');
});

it('adds only the lower bound when no upper bound was asked for', function (): void {
    $shape = ($this->shapeOf)(['occurredFrom' => CarbonImmutable::parse('2026-01-01 00:00:00')]);

    expect($shape->sql)->toContain('"occurred_at" >= ?')
        ->and($shape->sql)->not->toContain('"occurred_at" <= ?');
});

it('filters by actor type alone', function (): void {
    $shape = ($this->shapeOf)(['actorType' => ActorType::Automation]);

    expect($shape->sql)->toContain('"actor_type" = ?')
        ->and($shape->hasBinding('automation'))->toBeTrue()
        ->and($shape->sql)->not->toContain('"actor_id"');
});

it('narrows an actor type down to one concrete actor id', function (): void {
    $actorId = ModelStub::ulid('actor');

    $shape = ($this->shapeOf)(['actorType' => ActorType::User, 'actorId' => $actorId]);

    expect($shape->sql)->toContain('"actor_type" = ?')
        ->and($shape->sql)->toContain('"actor_id" = ?')
        ->and($shape->hasBinding($actorId))->toBeTrue();
});

it('combines source, range and actor into one conjunction', function (): void {
    $shape = ($this->shapeOf)([
        'sourceKeys' => ['note'],
        'occurredFrom' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        'occurredTo' => CarbonImmutable::parse('2026-01-31 00:00:00'),
        'actorType' => ActorType::User,
        'actorId' => ModelStub::ulid('actor'),
    ]);

    expect($shape->sql)->not->toContain(' or ')
        ->and($shape->sql)->toContain('"source_key" in (?)')
        ->and($shape->sql)->toContain('"occurred_at" >= ?')
        ->and($shape->sql)->toContain('"occurred_at" <= ?')
        ->and($shape->sql)->toContain('"actor_type" = ?')
        ->and($shape->sql)->toContain('"actor_id" = ?');
});

it('walks on from a well formed cursor instead of starting over', function (): void {
    $cursor = (new Cursor(['occurred_at' => '2026-01-05 00:00:00', 'id' => ModelStub::ulid('cursor')]))->encode();

    $shape = ($this->shapeOf)(['cursor' => $cursor]);

    expect($shape->sql)->toContain('("occurred_at" < ? or ("occurred_at" = ? and ("id" < ?)))')
        ->and($shape->bindings)->toContain('2026-01-05 00:00:00')
        ->and($shape->hasBinding(ModelStub::ulid('cursor')))->toBeTrue();
});

it('starts at page one for a cursor it cannot walk on from', function (string $cursor): void {
    $shape = ($this->shapeOf)(['cursor' => $cursor]);

    expect($shape->sql)->not->toContain('"occurred_at" <')
        ->and($shape->bindings)->toHaveCount(3);
})->with([
    'undecodable garbage' => 'not-a-cursor-at-all',
    'foreign parameter names' => fn (): string => (new Cursor(['created_at' => '2026-01-05 00:00:00', 'uuid' => 'x']))->encode(),
    'only the occurrence column' => fn (): string => (new Cursor(['occurred_at' => '2026-01-05 00:00:00']))->encode(),
    'only the key column' => fn (): string => (new Cursor(['id' => ModelStub::ulid('cursor')]))->encode(),
]);

it('ignores a foreign cursor that sits in the request query string instead of the argument', function (): void {
    $encoded = (new Cursor(['occurred_at' => '2026-01-05 00:00:00', 'id' => 'x']))->encode();

    app()->instance('request', Request::create('/?timelineCursor='.$encoded, 'GET'));

    $shape = ($this->shapeOf)();

    expect($shape->sql)->not->toContain('"occurred_at" <')
        ->and($shape->bindings)->toHaveCount(3);
});
