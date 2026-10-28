<?php

declare(strict_types=1);

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Handlers\Timeline\FileTimelineSource;
use App\Handlers\Timeline\NoteTimelineSource;
use App\Handlers\Timeline\ReminderTimelineSource;
use App\Models\Attachment;
use App\Models\RecordNote;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Timeline\TimelineSourceRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registry = fn (): TimelineSourceRegistry => app(TimelineSourceRegistry::class);

    $this->shippedKeys = ['activity', 'field_change', 'reminder', 'file', 'note', 'merge', 'relation', 'approval'];

    $this->keyPattern = (string) (new ReflectionProperty(FilterTreeValidator::class, 'keyPattern'))->getDefaultValue();
});

it('resolves a registered key to an instance of its mapped source class', function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);

    $registry = ($this->registry)();

    expect($registry->hasSource('note'))->toBeTrue()
        ->and($registry->sourceFor('note'))->toBeInstanceOf(NoteTimelineSource::class)
        ->and($registry->sourceFor('note')->key())->toBe('note')
        ->and($registry->sourceFor('note')->modelClass())->toBe(RecordNote::class);
});

it('resolves a source added at runtime without a change to a core file', function (): void {
    $before = config('timeline.sources');

    expect($before)->toBeArray()
        ->and($before)->not->toHaveKey('plugin_source');

    config(['timeline.sources' => [...$before, 'plugin_source' => NoteTimelineSource::class]]);

    $registry = ($this->registry)();

    expect($registry->hasSource('plugin_source'))->toBeTrue()
        ->and($registry->sourceFor('plugin_source'))->toBeInstanceOf(NoteTimelineSource::class)
        ->and(config('timeline.sources'))->toHaveKeys($this->shippedKeys);
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

it('uses the very same key pattern characters as the filter tree validator', function (): void {
    $registryPattern = (new ReflectionProperty(TimelineSourceRegistry::class, 'keyPattern'))->getDefaultValue();

    expect($this->keyPattern)->not->toBe('')
        ->and($registryPattern)->toBe($this->keyPattern);
});

it('ships a source map that declares exactly the planned keys against real handler classes', function (): void {
    /** @var array<string, string> $sources */
    $sources = (require config_path('timeline.php'))['sources'];

    expect(array_keys($sources))->toEqualCanonicalizing($this->shippedKeys);

    foreach ($sources as $key => $class) {
        expect(preg_match($this->keyPattern, $key))->toBe(1)
            ->and($class)->toStartWith('App\\Handlers\\Timeline\\')
            ->and(is_a($class, TimelineSourceInterface::class, true))->toBeTrue()
            ->and(app($class)->key())->toBe($key);
    }
});
