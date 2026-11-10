<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Engine\StorageStrategy;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Support\ConfigBundle\ConfigBundleReader;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\SchemaVersion\BundleSchemaMigratorRegistry;
use App\Support\ConfigBundle\YamlCodec;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->root = sys_get_temp_dir().'/nubos-roundtrip-'.bin2hex(random_bytes(6));
    $this->directory = $this->root.'/tree';

    $this->currentVersion = (new BundleSchemaMigratorRegistry)->currentVersion();

    $this->bundle = fn (): ConfigBundle => new ConfigBundle(
        new BundleManifest($this->currentVersion, 'Acme Sales', [
            ArtifactKind::ObjectTypes->value => 1,
            ArtifactKind::Segments->value => 1,
        ], ['segments:deal:Open Deals → filter.owner_id: nicht auflösbar']),
        [
            new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', [
                'key' => 'deal',
                'name' => 'Deal',
                'storage_strategy' => StorageStrategy::Generic->value,
                'retention_days' => 365,
                'dedup_keys' => ['name'],
            ]),
            new BundleArtifact(ArtifactKind::Segments, 'deal:Open Deals', [
                'key' => 'deal:Open Deals',
                'is_system' => false,
                'filter_definition' => ['and' => [['field' => 'stage', 'value' => 'deal:default:open']]],
            ]),
        ],
    );

    $this->export = function (): ConfigBundle {
        $bundle = ($this->bundle)();

        app(ConfigBundleSerializer::class)->writeTo($bundle, $this->directory);

        return $bundle;
    };

    $this->read = fn (): ConfigBundle => app(ConfigBundleReader::class)->readFrom($this->directory);

    $this->fingerprint = static function (ConfigBundle $bundle): array {
        $entries = array_map(
            static fn (BundleArtifact $artifact): string => $artifact->kind->identifierFor($artifact->key).'@'.$artifact->hash(),
            $bundle->artifacts,
        );

        sort($entries);

        return $entries;
    };

    $this->rewriteManifest = function (callable $mutate): void {
        $codec = app(YamlCodec::class);
        $path = $this->directory.'/manifest.yaml';

        $manifest = $codec->parse(File::get($path), 'manifest.yaml');

        File::put($path, $codec->dump($mutate($manifest)));
    };

    $this->treeOf = function (): array {
        $tree = [];

        foreach (File::allFiles($this->directory) as $file) {
            $tree[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }

        ksort($tree);

        return $tree;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('keeps kind, business key, hash and manifest of every artifact across write and read', function (): void {
    $written = ($this->export)();
    $restored = ($this->read)();

    $declaredDependencies = array_merge(
        array_map(static fn (BundleArtifact $artifact): array => $artifact->dependsOn, $written->artifacts),
        array_map(static fn (BundleArtifact $artifact): array => $artifact->dependsOn, $restored->artifacts),
    );

    expect($written->artifacts)->not->toBeEmpty()
        ->and($restored->artifacts)->toHaveCount(count($written->artifacts))
        ->and(($this->fingerprint)($restored))->toBe(($this->fingerprint)($written))
        ->and($restored->manifest->toArray())->toBe($written->manifest->toArray())
        ->and(array_values(array_unique($declaredDependencies, SORT_REGULAR)))->toBe([[]]);
});

it('lifts a manifest that was written back to schema version one and reaches the original bundle', function (): void {
    $written = ($this->export)();

    ($this->rewriteManifest)(static function (array $manifest): array {
        $manifest['schema_version'] = 1;
        $manifest['generated_at'] = '2026-01-01T00:00:00+00:00';
        $manifest['environment'] = 'production';

        return $manifest;
    });

    $restored = ($this->read)();

    expect($restored->manifest->schemaVersion)->toBe($this->currentVersion)
        ->and($restored->manifest->schemaVersion)->not->toBe(1)
        ->and(($this->fingerprint)($restored))->toBe(($this->fingerprint)($written))
        ->and($restored->manifest->toArray())->toBe($written->manifest->toArray());
});

it('refuses a manifest from a newer schema version with the typed schema exception', function (): void {
    ($this->export)();

    ($this->rewriteManifest)(static function (array $manifest): array {
        $manifest['schema_version'] = 99;

        return $manifest;
    });

    try {
        ($this->read)();
    } catch (UnsupportedBundleSchemaVersionException $exception) {
        expect($exception)->not->toBeInstanceOf(MalformedBundleException::class)
            ->and($exception->foundVersion)->toBe(99)
            ->and($exception->getMessage())->toContain('99');

        return;
    }

    $this->fail('Expected an UnsupportedBundleSchemaVersionException for schema version 99.');
});

it('refuses a manifest that carries an unknown key and one that misses a known key', function (): void {
    ($this->export)();

    ($this->rewriteManifest)(static function (array $manifest): array {
        $manifest['exported_by'] = 'Acme Sales';

        return $manifest;
    });

    $unknownKeyRefusal = null;

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        $unknownKeyRefusal = $exception;
    }

    ($this->rewriteManifest)(static function (array $manifest): array {
        unset($manifest['exported_by'], $manifest['warnings']);

        return $manifest;
    });

    $missingKeyRefusal = null;

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        $missingKeyRefusal = $exception;
    }

    expect($unknownKeyRefusal)->toBeInstanceOf(MalformedBundleException::class)
        ->and($unknownKeyRefusal?->getMessage())->toContain('exported_by')
        ->and($unknownKeyRefusal?->getMessage())->not->toContain('fehlen')
        ->and($missingKeyRefusal)->toBeInstanceOf(MalformedBundleException::class)
        ->and($missingKeyRefusal?->getMessage())->toContain('warnings')
        ->and($missingKeyRefusal?->getMessage())->not->toContain('unbekannte');
});

it('refuses a manifest whose warnings are neither a list nor a list of strings', function (): void {
    ($this->export)();

    ($this->rewriteManifest)(static function (array $manifest): array {
        $manifest['warnings'] = 'kaputt';

        return $manifest;
    });

    $notAList = null;

    try {
        ($this->read)();
    } catch (InvalidArgumentException $exception) {
        $notAList = $exception;
    }

    ($this->rewriteManifest)(static function (array $manifest): array {
        $manifest['warnings'] = [42];

        return $manifest;
    });

    $notStrings = null;

    try {
        ($this->read)();
    } catch (InvalidArgumentException $exception) {
        $notStrings = $exception;
    }

    expect($notAList)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($notAList?->getMessage())->toContain('Liste von Warnungen als Text')
        ->and($notStrings)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($notStrings?->getMessage())->toContain('Jede Warnung im Paketmanifest muss ein Text sein');
});

it('refuses an empty artifact file instead of reading it as an empty mapping', function (): void {
    ($this->export)();

    File::put($this->directory.'/object-types/deal.yaml', '');

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        expect($exception->getMessage())->toContain('object-types/deal.yaml');

        return;
    }

    $this->fail('Expected a MalformedBundleException for a zero byte artifact file.');
});

it('refuses an unparsable artifact file and names the relative path', function (): void {
    ($this->export)();

    File::put($this->directory.'/object-types/deal.yaml', "key: deal\n  broken: [unclosed\n\t- tab\n");

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        expect($exception->getMessage())->toContain('object-types/deal.yaml');

        return;
    }

    $this->fail('Expected a MalformedBundleException for an unparsable artifact file.');
});

it('refuses an artifact file without a business key', function (): void {
    ($this->export)();

    File::put($this->directory.'/object-types/deal.yaml', "name: Deal\n");

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        expect($exception->getMessage())->toContain('object-types/deal.yaml');

        return;
    }

    $this->fail('Expected a MalformedBundleException for an artifact file without a business key.');
});

it('refuses a tree entry that does not sit exactly one artifact kind directory deep', function (string $relative): void {
    ($this->export)();

    $path = $this->directory.'/'.$relative;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "key: stray\n");

    expect(fn (): ConfigBundle => ($this->read)())->toThrow(MalformedBundleException::class);
})->with([
    'an unknown directory' => ['nonsense/entry.yaml'],
    'a stray file at the top level' => ['readme.yaml'],
    'a file nested two levels deep' => ['object-types/nested/deal.yaml'],
    'a file that is not yaml' => ['object-types/deal.json'],
]);

it('normalises enums, dates and floats into portable values and stays idempotent', function (): void {
    $codec = app(YamlCodec::class);

    $probe = ['tiny' => 0.1, 'third' => 1 / 3, 'exact' => 2.0];

    $normalised = $codec->normalize([
        'kind' => ArtifactKind::Reports,
        'moment' => new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('Europe/Berlin')),
        'count' => 3,
        'flag' => true,
        'nothing' => null,
        'nested' => ['thresholds' => $probe],
    ]);

    $offenders = [];

    foreach ($probe as $name => $value) {
        $candidate = $normalised['nested']['thresholds'][$name] ?? null;

        if (!is_string($candidate) || (float) $candidate !== $value) {
            $offenders[] = $name;
        }
    }

    expect($normalised['kind'])->toBe(ArtifactKind::Reports->value)
        ->and($normalised['moment'])->toBe('2026-01-02T02:04:05+00:00')
        ->and($normalised['count'])->toBe(3)
        ->and($normalised['flag'])->toBeTrue()
        ->and($normalised['nothing'])->toBeNull()
        ->and($offenders)->toBe([])
        ->and($codec->normalize($normalised))->toBe($normalised);
});

it('refuses a value it cannot represent and names the payload path and the type', function (): void {
    $codec = app(YamlCodec::class);

    expect(fn (): array => $codec->normalize(['ratio' => NAN]))->toThrow(MalformedBundleException::class)
        ->and(fn (): array => $codec->normalize(['ratio' => INF]))->toThrow(MalformedBundleException::class);

    try {
        $codec->normalize(['nodes' => [['config' => ['handler' => new stdClass]]]]);
    } catch (MalformedBundleException $exception) {
        expect($exception->getMessage())->toContain('nodes')
            ->and($exception->getMessage())->toContain('config')
            ->and($exception->getMessage())->toContain('handler')
            ->and($exception->getMessage())->toContain('stdClass');

        return;
    }

    $this->fail('Expected a MalformedBundleException naming the path and the type of an unrepresentable value.');
});

it('refuses a tree without a manifest and a directory that does not exist at all', function (): void {
    ($this->export)();

    File::delete($this->directory.'/manifest.yaml');

    $missingManifest = null;

    try {
        ($this->read)();
    } catch (MalformedBundleException $exception) {
        $missingManifest = $exception;
    }

    $missingDirectory = null;

    try {
        app(ConfigBundleReader::class)->readFrom($this->root.'/does-not-exist');
    } catch (MalformedBundleException $exception) {
        $missingDirectory = $exception;
    }

    expect($missingManifest)->toBeInstanceOf(MalformedBundleException::class)
        ->and($missingManifest?->getMessage())->toContain('manifest.yaml')
        ->and($missingDirectory)->toBeInstanceOf(MalformedBundleException::class)
        ->and($missingDirectory?->getMessage())->toContain('manifest.yaml');
});

it('carries an enum backed column through write and read as the same plain string and never as a php tag', function (): void {
    $written = ($this->export)();
    $restored = ($this->read)();

    $writtenStrategy = $written->find(ArtifactKind::ObjectTypes, 'deal')?->payload['storage_strategy'] ?? null;
    $restoredStrategy = $restored->find(ArtifactKind::ObjectTypes, 'deal')?->payload['storage_strategy'] ?? null;

    $offenders = [];

    foreach (($this->treeOf)() as $relative => $content) {
        if (str_contains($content, '!php/')) {
            $offenders[] = "php tag in {$relative}";
        }

        if (str_contains($content, 'App\\')) {
            $offenders[] = "class name in {$relative}";
        }
    }

    expect($writtenStrategy)->toBeString()
        ->and($writtenStrategy)->toBe(StorageStrategy::Generic->value)
        ->and($restoredStrategy)->toBe($writtenStrategy)
        ->and($offenders)->toBe([]);
});
