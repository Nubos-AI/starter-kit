<?php

declare(strict_types=1);

use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\UnsupportedArtifactKindException;
use App\Providers\AppServiceProvider;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use Illuminate\Contracts\Container\BindingResolutionException;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Tests\Support\Doubles\ReportsArtifactWriter;
use Tests\Support\Doubles\RolesArtifactWriter;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->useWriters = function (mixed $writers): void {
        config(['engine.config_bundle.writers' => $writers]);
    };
});

it('builds the registry from exactly the configured writers, in configuration order', function (): void {
    ($this->useWriters)([
        RolesArtifactWriter::class,
        ReportsArtifactWriter::class,
    ]);

    $registry = app(ArtifactWriterRegistry::class);
    $writers = $registry->all();

    expect($writers)->toHaveCount(2)
        ->and($writers[0])->toBeInstanceOf(RolesArtifactWriter::class)
        ->and($writers[1])->toBeInstanceOf(ReportsArtifactWriter::class)
        ->and($registry->for(ArtifactKind::Roles))->toBe($writers[0])
        ->and($registry->for(ArtifactKind::Reports))->toBe($writers[1]);
});

it('throws the typed unsupported kind exception for a kind that no wired writer carries', function (): void {
    ($this->useWriters)([
        RolesArtifactWriter::class,
        ReportsArtifactWriter::class,
    ]);

    $registry = app(ArtifactWriterRegistry::class);

    expect($registry->for(ArtifactKind::Roles))->toBeInstanceOf(RolesArtifactWriter::class);

    try {
        $registry->for(ArtifactKind::Dashboards);
    } catch (UnsupportedArtifactKindException $exception) {
        expect($exception->kind)->toBe(ArtifactKind::Dashboards)
            ->and($exception->getMessage())->toContain(ArtifactKind::Dashboards->value);

        return;
    }

    $this->fail('Expected an UnsupportedArtifactKindException to be thrown.');
});

it('rejects a configured class that exists but does not implement the writer contract', function (): void {
    ($this->useWriters)([
        RolesArtifactWriter::class,
        stdClass::class,
    ]);

    try {
        app(ArtifactWriterRegistry::class);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('stdClass')
            ->and($exception->getMessage())->toContain('engine.config_bundle.writers');

        return;
    }

    $this->fail('Expected an InvalidArgumentException naming the offending entry to be thrown.');
});

it('rejects an entry that is not a class name at all and names its type instead of casting it', function (): void {
    ($this->useWriters)([
        RolesArtifactWriter::class,
        ['App\Handlers\ConfigBundle\ObjectModelArtifactWriter'],
    ]);

    try {
        app(ArtifactWriterRegistry::class);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('array')
            ->and($exception->getMessage())->toContain('engine.config_bundle.writers');

        return;
    }

    $this->fail('Expected an InvalidArgumentException describing the non-string entry to be thrown.');
});

it('rejects a configured class name that does not exist without ever reaching the container', function (): void {
    ($this->useWriters)(['Tests\Doubles\ConfigBundle\NoSuchArtifactWriterAtAll']);

    try {
        app(ArtifactWriterRegistry::class);
    } catch (Throwable $throwable) {
        expect($throwable)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($throwable)->not->toBeInstanceOf(BindingResolutionException::class)
            ->and($throwable->getMessage())->toContain('NoSuchArtifactWriterAtAll')
            ->and($throwable->getMessage())->toContain('engine.config_bundle.writers');

        return;
    }

    $this->fail('Expected an InvalidArgumentException instead of a container binding failure.');
});

it('leaves the writer list unevaluated while the provider registers and fails only on resolution', function (): void {
    ($this->useWriters)([stdClass::class]);

    $provider = new AppServiceProvider($this->app);

    $provider->register();

    expect(app(DataConverterInterface::class))->toBeInstanceOf(DataConverter::class);

    expect(fn (): ArtifactWriterRegistry => app(ArtifactWriterRegistry::class))
        ->toThrow(InvalidArgumentException::class);
});

it('hands out a fresh registry with fresh writer instances on every resolution', function (): void {
    ($this->useWriters)([RolesArtifactWriter::class]);

    $first = app(ArtifactWriterRegistry::class);
    $second = app(ArtifactWriterRegistry::class);

    expect($first)->toBeInstanceOf(ArtifactWriterRegistry::class)
        ->and($second)->not->toBe($first)
        ->and($first->all())->toHaveCount(1)
        ->and($second->all())->toHaveCount(1)
        ->and($first->all()[0])->toBeInstanceOf(RolesArtifactWriter::class)
        ->and($second->all()[0])->toBeInstanceOf(RolesArtifactWriter::class)
        ->and($second->all()[0])->not->toBe($first->all()[0]);
});

it('accepts an empty writer list and builds an empty registry that still refuses every kind loudly', function (): void {
    ($this->useWriters)([]);

    $registry = app(ArtifactWriterRegistry::class);

    expect($registry)->toBeInstanceOf(ArtifactWriterRegistry::class)
        ->and($registry->all())->toBe([]);

    try {
        $registry->for(ArtifactKind::Roles);
    } catch (UnsupportedArtifactKindException $exception) {
        expect($exception->kind)->toBe(ArtifactKind::Roles);

        return;
    }

    $this->fail('Expected an UnsupportedArtifactKindException to be thrown.');
});

it('rejects a writer list that is not an array instead of falling back to an empty registry', function (mixed $writers): void {
    ($this->useWriters)($writers);

    expect(fn (): ArtifactWriterRegistry => app(ArtifactWriterRegistry::class))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing key' => [null],
    'single class name instead of a list' => [RolesArtifactWriter::class],
    'integer' => [42],
    'object' => [(object) ['first' => RolesArtifactWriter::class]],
]);
