<?php

declare(strict_types=1);

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Enums\CustomFields\FieldType;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Handlers\Timeline\FileTimelineSource;
use App\Handlers\Timeline\NoteTimelineSource;
use App\Handlers\Timeline\ReminderTimelineSource;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use App\Models\RecordNote;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Timeline\TimelineSourceRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registry = fn (): TimelineSourceRegistry => app(TimelineSourceRegistry::class);

    $this->shippedKeys = ['activity', 'field_change', 'reminder', 'file', 'note', 'merge', 'relation', 'approval'];
});

it('resolves a registered key to an instance of its mapped source class', function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);

    $registry = ($this->registry)();

    expect($registry->hasSource('note'))->toBeTrue()
        ->and($registry->sourceFor('note'))->toBeInstanceOf(NoteTimelineSource::class)
        ->and($registry->sourceFor('note')->key())->toBe('note')
        ->and($registry->sourceFor('note')->modelClass())->toBe(RecordNote::class);
});

it('throws an exception carrying the offending key for an unregistered source', function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);

    $registry = ($this->registry)();

    expect($registry->hasSource('nope'))->toBeFalse();

    try {
        $registry->sourceFor('nope');
    } catch (UnknownTimelineSourceException $exception) {
        expect($exception->sourceKey)->toBe('nope');

        return;
    }

    $this->fail('the registry resolved a key it never knew');
});

it('rejects a key that violates the key pattern even though the entry exists', function (string $key): void {
    config(['timeline.sources' => [$key => NoteTimelineSource::class]]);

    $registry = ($this->registry)();

    expect(config('timeline.sources'))->toHaveKey($key)
        ->and($registry->hasSource($key))->toBeFalse()
        ->and(fn (): TimelineSourceInterface => $registry->sourceFor($key))
        ->toThrow(UnknownTimelineSourceException::class);
})->with([
    'uppercase letter' => 'Foo',
    'leading digit' => '1x',
    'hyphen' => 'a-b',
    'inner space' => 'a b',
    'empty string' => '',
]);

it('rejects a mapped class that exists but does not implement the source contract', function (): void {
    config(['timeline.sources' => ['note' => stdClass::class]]);

    $registry = ($this->registry)();

    expect($registry->hasSource('note'))->toBeFalse()
        ->and(fn (): TimelineSourceInterface => $registry->sourceFor('note'))
        ->toThrow(UnknownTimelineSourceException::class);
});

it('rejects a mapped class that does not exist yet with a typed exception instead of a fatal error', function (): void {
    config(['timeline.sources' => ['note' => 'App\\Handlers\\Timeline\\NotYetExistingSource']]);

    $registry = ($this->registry)();

    expect(class_exists('App\\Handlers\\Timeline\\NotYetExistingSource'))->toBeFalse()
        ->and($registry->hasSource('note'))->toBeFalse()
        ->and(fn (): TimelineSourceInterface => $registry->sourceFor('note'))
        ->toThrow(UnknownTimelineSourceException::class);
});

it('keeps two source keys that point at the same model class side by side', function (): void {
    config(['timeline.sources' => [
        'file' => FileTimelineSource::class,
        'document' => FileTimelineSource::class,
    ]]);

    $registry = ($this->registry)();

    expect($registry->sourceFor('file')->modelClass())->toBe(Attachment::class)
        ->and($registry->sourceFor('document')->modelClass())->toBe(Attachment::class)
        ->and($registry->keys())->toBe(['file', 'document']);
});

it('lists only resolvable keys and keeps them in configuration order', function (): void {
    config(['timeline.sources' => [
        'zebra_source' => FileTimelineSource::class,
        'Foo' => NoteTimelineSource::class,
        'alpha_source' => ReminderTimelineSource::class,
        'missing_source' => 'App\\Handlers\\Timeline\\NotYetExistingSource',
        'broken_source' => stdClass::class,
    ]]);

    expect(($this->registry)()->keys())->toBe(['zebra_source', 'alpha_source']);
});

it('accepts and rejects a key exactly like the filter tree validator does', function (string $key, bool $isWellFormed): void {
    config(['timeline.sources' => [$key => NoteTimelineSource::class]]);

    $field = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("pattern-field-{$key}"),
        'key' => $key,
        'field_type' => FieldType::TextShort,
        'is_filterable' => true,
        'is_sortable' => false,
        'is_encrypted' => false,
        'is_translatable' => false,
        'config' => [],
    ]);
    $validation = fn () => app(FilterTreeValidator::class)->validateDefinition(
        ['field' => $key, 'operator' => 'equals', 'value' => 'x'],
        new EloquentCollection([$field]),
    );

    expect(($this->registry)()->hasSource($key))->toBe($isWellFormed);

    $isWellFormed
        ? expect($validation)->not->toThrow(InvalidFilterTreeException::class)
        : expect($validation)->toThrow(InvalidFilterTreeException::class);
})->with([
    'a lowercase word' => ['note', true],
    'an underscore inside' => ['field_change', true],
    'a trailing digit' => ['a1', true],
    'an uppercase letter' => ['Foo', false],
    'a leading digit' => ['1x', false],
    'a hyphen' => ['a-b', false],
    'an inner space' => ['a b', false],
    'a leading underscore' => ['_note', false],
]);

it('ships a source map that declares exactly the planned keys against real handler classes', function (): void {
    /** @var array<string, string> $sources */
    $sources = (require config_path('timeline.php'))['sources'];

    expect(array_keys($sources))->toEqualCanonicalizing($this->shippedKeys);

    foreach ($sources as $key => $class) {
        expect(($this->registry)()->hasSource($key))->toBeTrue()
            ->and($class)->toStartWith('App\\Handlers\\Timeline\\')
            ->and(is_a($class, TimelineSourceInterface::class, true))->toBeTrue()
            ->and(app($class)->key())->toBe($key);
    }
});
