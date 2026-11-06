<?php

declare(strict_types=1);

use App\Contracts\Links\SourceLinkResolverInterface;
use App\Exceptions\Links\UnknownSourceLinkException;
use App\Handlers\Links\RecordSourceLinkResolver;
use App\Support\Links\SourceLinkRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registry = fn (): SourceLinkRegistry => app(SourceLinkRegistry::class);
});

it('ships the record resolver under the record key and nothing else', function (): void {
    /** @var array<string, string> $shipped */
    $shipped = (require config_path('links.php'))['sources'];

    expect(array_keys($shipped))->toBe(['record'])
        ->and(($this->registry)()->keys())->toContain('record')
        ->and(($this->registry)()->resolverFor('record'))->toBeInstanceOf(RecordSourceLinkResolver::class)
        ->and(($this->registry)()->resolverFor('record')->key())->toBe('record');
});

it('resolves a source a package merges in at runtime without a change to a core file', function (): void {
    config(['links.sources.email_message' => RecordSourceLinkResolver::class]);

    $registry = ($this->registry)();

    expect($registry->has('record'))->toBeTrue()
        ->and($registry->resolverFor('record'))->toBeInstanceOf(RecordSourceLinkResolver::class)
        ->and($registry->has('email_message'))->toBeTrue()
        ->and($registry->resolverFor('email_message'))->toBeInstanceOf(SourceLinkResolverInterface::class)
        ->and($registry->keys())->toBe(['record', 'email_message']);
});

it('throws an exception carrying the offending key for an unregistered source', function (): void {
    $registry = ($this->registry)();

    expect($registry->has('nope'))->toBeFalse();

    try {
        $registry->resolverFor('nope');
    } catch (UnknownSourceLinkException $exception) {
        expect($exception->sourceType)->toBe('nope');

        return;
    }

    $this->fail('the registry resolved a source type it never knew');
});

it('lists only keys that follow the key pattern and map onto a real resolver', function (): void {
    config(['links.sources' => [
        'record' => RecordSourceLinkResolver::class,
        'Bad-Key' => RecordSourceLinkResolver::class,
        'broken' => stdClass::class,
        'missing' => 'App\\Handlers\\Links\\NotYetExistingResolver',
    ]]);

    $registry = ($this->registry)();

    expect($registry->keys())->toBe(['record'])
        ->and($registry->has('Bad-Key'))->toBeFalse()
        ->and(fn (): SourceLinkResolverInterface => $registry->resolverFor('broken'))
        ->toThrow(UnknownSourceLinkException::class);
});
