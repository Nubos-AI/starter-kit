<?php

declare(strict_types=1);

use App\Models\Segment;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(bool):Segment */
    $this->segment = fn (bool $isSystem): Segment => ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid($isSystem ? 'system-segment' : 'plain-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('segment-owner'),
        'name' => 'Sicht',
        'object_type_id' => ModelStub::ulid('guard-type'),
        'is_system' => $isSystem,
        'is_default' => false,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('stops a change to a system view before it ever reaches the database', function (): void {
    $segment = ($this->segment)(true);
    $segment->name = 'Umbenannt';

    $shape = QueryShape::attemptedBy(function () use ($segment): void {
        try {
            $segment->save();
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): bool => $segment->save())->toThrow(AuthorizationException::class)
        ->and($shape)->toBeNull();
});

it('stops the deletion of a system view before it ever reaches the database', function (): void {
    $segment = ($this->segment)(true);

    $shape = QueryShape::attemptedBy(function () use ($segment): void {
        try {
            $segment->delete();
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn () => $segment->delete())->toThrow(AuthorizationException::class)
        ->and($shape)->toBeNull();
});

it('judges the system flag by the stored value so the flag cannot be lowered on the way in', function (): void {
    $segment = ($this->segment)(true);
    $segment->is_system = false;

    expect(fn (): bool => $segment->save())->toThrow(AuthorizationException::class);
});

it('lets an ordinary view through the guard to the database', function (): void {
    $segment = ($this->segment)(false);
    $segment->name = 'Umbenannt';

    expect(WriteAttempt::reachedTheDatabase(fn (): bool => $segment->save()))->toBeTrue();
});

it('keeps the tenant scope and the soft delete filter on every segment query', function (): void {
    $shape = QueryShape::of(Segment::class);

    expect($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('segments'))->toBeTrue();
});

it('offers a cross object view by leaving the object type empty rather than by a marker value', function (): void {
    $segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('cross-object'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => null,
        'is_system' => false,
    ]);

    expect($segment->object_type_id)->toBeNull();
});

it('reads the stored filter tree and the flags back as php values rather than as raw json', function (): void {
    $tree = ['combinator' => 'and', 'conditions' => []];

    $segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('cast-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'filter_definition' => $tree,
        'i18n_labels' => ['de' => 'Sicht'],
        'is_system' => 1,
        'is_default' => 0,
    ]);

    expect($segment->filter_definition)->toBe($tree)
        ->and($segment->i18n_labels)->toBe(['de' => 'Sicht'])
        ->and($segment->is_system)->toBeTrue()
        ->and($segment->is_default)->toBeFalse();
});
