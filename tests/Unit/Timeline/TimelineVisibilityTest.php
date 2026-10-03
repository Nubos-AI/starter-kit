<?php

declare(strict_types=1);

use App\Handlers\Timeline\FileTimelineSource;
use App\Handlers\Timeline\NoteTimelineSource;
use App\Handlers\Timeline\RelationTimelineSource;
use App\Handlers\Timeline\ReminderTimelineSource;
use App\Http\Resources\Timeline\TimelineEntryResource;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Support\Timeline\TimelineSourceRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('timeline-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('object-type'),
    ]);

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return EloquentCollection<int, TimelineEntry>
     */
    $this->entries = function (array $rows): EloquentCollection {
        return ModelStub::collection(TimelineEntry::class, $rows);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('withholds every note row from a user who may not view the record and never asks the note table', function (): void {
    $spy = GateSpy::allowing();

    $entries = ($this->entries)([
        ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-one')],
    ]);

    $visible = null;

    $shape = QueryShape::attemptedBy(function () use (&$visible, $entries): void {
        $visible = (new NoteTimelineSource)->filterVisible($this->user, $this->record, $entries);
    });

    expect($shape)->toBeNull()
        ->and($visible)->toHaveCount(0)
        ->and($spy->wasAskedFor('view'))->toBeTrue();
});

it('withholds every file, reminder and relation row from a user who may not view the record', function (): void {
    GateSpy::allowing();

    $entries = ($this->entries)([
        ['source_key' => 'file', 'source_id' => ModelStub::ulid('file-one'), 'payload' => ['fieldKey' => 'contract']],
    ]);

    $sources = [
        new FileTimelineSource,
        new ReminderTimelineSource,
        app(RelationTimelineSource::class),
    ];

    foreach ($sources as $source) {
        $visible = null;

        $shape = QueryShape::attemptedBy(function () use (&$visible, $source, $entries): void {
            $visible = $source->filterVisible($this->user, $this->record, $entries);
        });

        expect($shape)->toBeNull()
            ->and($visible)->toHaveCount(0);
    }
});

it('asks nothing at all for an empty entry list although the user may view the record', function (): void {
    GateSpy::allowing('view');

    $visible = null;

    $shape = QueryShape::attemptedBy(function () use (&$visible): void {
        $visible = (new NoteTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([]));
    });

    expect($shape)->toBeNull()
        ->and($visible)->toHaveCount(0);
});

it('drops note rows without a source id before it reaches the note table', function (): void {
    GateSpy::allowing('view');

    $visible = null;

    $shape = QueryShape::attemptedBy(function () use (&$visible): void {
        $visible = (new NoteTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'note', 'source_id' => null],
        ]));
    });

    expect($shape)->toBeNull()
        ->and($visible)->toHaveCount(0);
});

it('resolves the bodies of many notes with a single query carrying every distinct source id', function (): void {
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(function (): void {
        (new NoteTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-one')],
            ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-two')],
            ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-one')],
        ]));
    });

    expect($shape)->not->toBeNull()
        ->and($shape->targets('record_notes'))->toBeTrue()
        ->and($shape->sql)->toContain('"id" in (?, ?)')
        ->and($shape->isScopedToTenant('record_notes', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('record_notes'))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('note-one')))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('note-two')))->toBeTrue();
});

it('drops the file row of a field the user may not read before the attachment is looked up', function (): void {
    GateSpy::allowing('view');
    new FakeFieldVisibilityResolver(['secret']);

    $shape = QueryShape::attemptedBy(function (): void {
        (new FileTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'file', 'source_id' => ModelStub::ulid('secret-file'), 'payload' => ['fieldKey' => 'secret']],
            ['source_key' => 'file', 'source_id' => ModelStub::ulid('open-file'), 'payload' => ['fieldKey' => 'contract']],
        ]));
    });

    expect($shape)->not->toBeNull()
        ->and($shape->targets('attachments'))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('open-file')))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('secret-file')))->toBeFalse()
        ->and($shape->hasBinding((string) $this->record->getKey()))->toBeTrue();
});

it('keeps a record attachment that belongs to no field at all', function (): void {
    GateSpy::allowing('view');
    new FakeFieldVisibilityResolver(['secret']);

    $shape = QueryShape::attemptedBy(function (): void {
        (new FileTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'file', 'source_id' => ModelStub::ulid('loose-file'), 'payload' => ['recordAttachment' => true]],
        ]));
    });

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding(ModelStub::ulid('loose-file')))->toBeTrue();
});

it('discards a file entry that names neither a field key nor a record attachment', function (): void {
    GateSpy::allowing('view');
    new FakeFieldVisibilityResolver;

    $shape = QueryShape::attemptedBy(function (): void {
        (new FileTimelineSource)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'file', 'source_id' => ModelStub::ulid('orphan-file'), 'payload' => []],
        ]));
    });

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding(ModelStub::ulid('orphan-file')))->toBeFalse();
});

it('collects the distinct relationship types of the relation rows in a single lookup', function (): void {
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(function (): void {
        app(RelationTimelineSource::class)->filterVisible($this->user, $this->record, ($this->entries)([
            ['source_key' => 'relation', 'payload' => ['relationship_type_id' => ModelStub::ulid('type-a')]],
            ['source_key' => 'relation', 'payload' => ['relationship_type_id' => ModelStub::ulid('type-b')]],
            ['source_key' => 'relation', 'payload' => ['relationship_type_id' => ModelStub::ulid('type-a')]],
            ['source_key' => 'relation', 'payload' => []],
        ]));
    });

    expect($shape)->not->toBeNull()
        ->and($shape->targets('relationship_types'))->toBeTrue()
        ->and($shape->sql)->toContain('"relationship_types"."id" in (?, ?)')
        ->and($shape->hidesSoftDeleted('relationship_types'))->toBeFalse()
        ->and($shape->hasBinding(ModelStub::ulid('type-a')))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('type-b')))->toBeTrue();
});

it('discards a row whose source key the registry does not know instead of raising for it', function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);
    GateSpy::allowing();

    $entries = ($this->entries)([
        ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-one'), 'occurred_at' => '2026-01-01 00:00:00'],
        ['source_key' => 'ghost', 'source_id' => ModelStub::ulid('ghost-one'), 'occurred_at' => '2026-01-02 00:00:00'],
    ]);

    $collection = TimelineEntryResource::redactedCollection(
        $this->user,
        $this->record,
        $entries,
        app(TimelineSourceRegistry::class),
    );

    expect($collection->collection)->toHaveCount(0);
});

it('drops an invisible row entirely instead of handing back an emptied one', function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);
    GateSpy::allowing();

    $collection = TimelineEntryResource::redactedCollection(
        $this->user,
        $this->record,
        ($this->entries)([
            ['source_key' => 'note', 'source_id' => ModelStub::ulid('note-one'), 'occurred_at' => '2026-01-01 00:00:00'],
        ]),
        app(TimelineSourceRegistry::class),
    );

    expect($collection->collection)->toBeEmpty();
});
