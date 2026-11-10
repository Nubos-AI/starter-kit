<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Models\Tenant;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\YamlCodec;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->root = sys_get_temp_dir().'/nubos-determinism-'.bin2hex(random_bytes(6));

    $this->floatProbe = static fn (): array => [
        'tiny' => 0.1,
        'third' => 1 / 3,
        'negative' => -2.5,
        'large' => 1.7976931348623157e308,
        'exact' => 2.0,
    ];

    $this->filterDefinition = fn (): array => [
        'combinator' => 'and',
        'thresholds' => ($this->floatProbe)(),
        'conditions' => [['field' => 'amount', 'operator' => 'gt', 'value' => '1000']],
    ];

    $this->bundleOf = fn (string $objectTypeName = 'Deal'): ConfigBundle => new ConfigBundle(
        new BundleManifest(4, 'Acme Sales', [
            ArtifactKind::ObjectTypes->value => 1,
            ArtifactKind::Reports->value => 1,
        ], ['object-types:deal → owner_id: nicht auflösbar']),
        [
            new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', [
                'key' => 'deal',
                'name' => $objectTypeName,
                'slug' => 'deals',
            ]),
            new BundleArtifact(ArtifactKind::Reports, 'deal:Revenue Q1', app(YamlCodec::class)->normalize([
                'key' => 'deal:Revenue Q1',
                'filter_definition' => ($this->filterDefinition)(),
            ])),
        ],
    );

    $this->writeInto = function (string $name, ConfigBundle $bundle): string {
        $directory = $this->root.'/'.$name;

        app(ConfigBundleSerializer::class)->writeTo($bundle, $directory);

        return $directory;
    };

    $this->hashes = static function (string $directory): array {
        $hashes = [];

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            $hashes[$relative] = hash_file('sha256', $file->getPathname());
        }

        ksort($hashes);

        return $hashes;
    };
});

afterEach(function (): void {
    $this->travelBack();
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('writes two exports of the same state byte for byte identically, manifest included', function (): void {
    $first = ($this->hashes)(($this->writeInto)('first', ($this->bundleOf)()));
    $second = ($this->hashes)(($this->writeInto)('second', ($this->bundleOf)()));

    expect($first)->not->toBeEmpty()
        ->and(array_keys($first))->toContain('manifest.yaml')
        ->and(array_keys($second))->toBe(array_keys($first))
        ->and($second)->toBe($first);
});

it('leaves the tree untouched when the clock moves a day forward between two exports', function (): void {
    $before = ($this->hashes)(($this->writeInto)('before', ($this->bundleOf)()));

    $this->travel(1)->days();

    $after = ($this->hashes)(($this->writeInto)('after', ($this->bundleOf)()));

    expect($before)->not->toBeEmpty()
        ->and($after)->toBe($before);
});

it('changes exactly one file when one artifact changes and leaves the manifest alone', function (): void {
    $before = ($this->hashes)(($this->writeInto)('before', ($this->bundleOf)()));
    $after = ($this->hashes)(($this->writeInto)('after', ($this->bundleOf)('Renamed Deal')));

    $changed = [];

    foreach ($after as $relative => $hash) {
        if (($before[$relative] ?? null) !== $hash) {
            $changed[] = $relative;
        }
    }

    expect(array_keys($after))->toBe(array_keys($before))
        ->and($changed)->toBe(['object-types/deal.yaml'])
        ->and($after['manifest.yaml'])->toBe($before['manifest.yaml']);
});

it('writes a float as its shortest lossless decimal string so the export stays stable and the value reads back exactly', function (): void {
    $probe = ($this->floatProbe)();
    $written = app(YamlCodec::class)->normalize(['thresholds' => $probe])['thresholds'];

    $offenders = [];

    foreach ($probe as $name => $value) {
        $candidate = $written[$name] ?? null;

        if (!is_string($candidate)) {
            $offenders[] = "{$name}: not written as a string";

            continue;
        }

        if ((float) $candidate !== $value) {
            $offenders[] = "{$name}: {$candidate} does not read back to the original float";
        }
    }

    expect($written)->toHaveCount(count($probe))
        ->and($offenders)->toBe([])
        ->and($written['tiny'])->toBe('0.1')
        ->and($written['exact'])->toBe('2');
});

it('keeps the payload key order across normalisation and mirrors it in the written file', function (): void {
    $codec = app(YamlCodec::class);
    $payload = ($this->filterDefinition)();

    $normalised = $codec->normalize($payload);
    $twice = $codec->normalize($normalised);

    $directory = ($this->writeInto)('order', ($this->bundleOf)());
    $parsed = $codec->parse(File::get($directory.'/reports/deal__revenue-q1.yaml'), 'reports/deal__revenue-q1.yaml');

    expect(array_keys($normalised))->toBe(array_keys($payload))
        ->and(array_keys($normalised['thresholds']))->toBe(array_keys($payload['thresholds']))
        ->and($twice)->toBe($normalised)
        ->and(array_keys($parsed['filter_definition']))->toBe(array_keys($payload))
        ->and(array_keys($parsed['filter_definition']['thresholds']))->toBe(array_keys($payload['thresholds']));
});

it('sorts and deduplicates the manifest warnings and counts every artifact kind', function (): void {
    $serializer = app(ConfigBundleSerializer::class);
    $build = new ReflectionMethod(ConfigBundleSerializer::class, 'manifest');

    $artifacts = ($this->bundleOf)()->artifacts;
    $warnings = [
        'webhook-subscriptions:Deal Sync → target_url: nicht auflösbar',
        'automations:deal:Lead Routing → nodes[1].config.fallback_user_id: nicht auflösbar',
        'automations:deal:Lead Routing → nodes[1].config.fallback_user_id: nicht auflösbar',
    ];

    /** @var BundleManifest $manifest */
    $manifest = $build->invoke($serializer, Tenant::make(['name' => 'Acme Sales']), $artifacts, $warnings);

    $sorted = $manifest->warnings;
    sort($sorted);

    expect($manifest->sourceLabel)->toBe('Acme Sales')
        ->and($manifest->warnings)->toHaveCount(2)
        ->and($manifest->warnings)->toBe($sorted)
        ->and($manifest->warnings)->toBe(array_values(array_unique($manifest->warnings)))
        ->and(array_keys($manifest->artifactCounts))->toBe(array_map(
            static fn (ArtifactKind $kind): string => $kind->value,
            ArtifactKind::cases(),
        ))
        ->and($manifest->artifactCounts[ArtifactKind::ObjectTypes->value])->toBe(1)
        ->and($manifest->artifactCounts[ArtifactKind::Reports->value])->toBe(1)
        ->and($manifest->artifactCounts[ArtifactKind::Skills->value])->toBe(0);
});
