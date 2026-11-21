<?php

declare(strict_types=1);

use App\Contracts\ConfigBundle\BundleSchemaMigratorInterface;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Support\ConfigBundle\SchemaVersion\BundleSchemaMigratorRegistry;
use App\Support\ConfigBundle\SchemaVersion\V1ToV2Migrator;
use Tests\Fixtures\ConfigBundle\BundleTreeFixture;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->noOpMigrator = static function (int $from): BundleSchemaMigratorInterface {
        return new class($from) implements BundleSchemaMigratorInterface
        {
            public function __construct(private readonly int $from) {}

            public function fromVersion(): int
            {
                return $this->from;
            }

            /**
             * @param  array<string, array<string, mixed>>  $tree
             * @return array<string, array<string, mixed>>
             */
            public function migrate(array $tree): array
            {
                return $tree;
            }
        };
    };

    $this->tracingMigrator = static function (int $from, string $label): BundleSchemaMigratorInterface {
        return new class($from, $label) implements BundleSchemaMigratorInterface
        {
            public function __construct(
                private readonly int $from,
                private readonly string $label,
            ) {}

            public function fromVersion(): int
            {
                return $this->from;
            }

            /**
             * @param  array<string, array<string, mixed>>  $tree
             * @return array<string, array<string, mixed>>
             */
            public function migrate(array $tree): array
            {
                $tree['manifest.yaml']['trace'][] = $this->label;

                return $tree;
            }
        };
    };

    $this->filterTree = static fn (string $value = '76'): array => [
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'value' => $value, 'operator' => 'startsWith'],
        ],
    ];

    /** @var callable(array<string, array<string, mixed>>):array{0: string, 1: array<string, mixed>} */
    $this->ruleEntryOf = static function (array $tree): array {
        $entries = array_filter(
            $tree,
            static fn (string $relative): bool => str_starts_with($relative, ArtifactKind::TeamRecordAccessRules->value.'/'),
            ARRAY_FILTER_USE_KEY,
        );

        expect($entries)->toHaveCount(1);

        $path = (string) array_key_first($entries);

        return [$path, $entries[$path]];
    };
});

it('upgrades a version one tree to version two by dropping generated_at only', function (): void {
    $before = BundleTreeFixture::v1();

    $result = (new V1ToV2Migrator)->migrate($before);

    expect($result['manifest.yaml'])->not->toHaveKey('generated_at')
        ->and($result['manifest.yaml'])->toBe(BundleTreeFixture::v2()['manifest.yaml'])
        ->and(array_keys($result['manifest.yaml']))->toBe([
            'schema_version',
            'environment',
            'source_label',
            'artifact_counts',
            'warnings',
        ]);
});

it('carries the warnings key through the upgrade untouched', function (): void {
    $registry = new BundleSchemaMigratorRegistry;
    $before = BundleTreeFixture::v1();

    $result = $registry->upgrade($before, 1);

    expect($before['manifest.yaml']['warnings'])->not->toBe([])
        ->and($result['manifest.yaml'])->toHaveKey('warnings')
        ->and($result['manifest.yaml']['warnings'])->toBe($before['manifest.yaml']['warnings']);
});

it('leaves every file outside manifest.yaml untouched by the upgrade', function (): void {
    $before = BundleTreeFixture::v1();
    $registry = new BundleSchemaMigratorRegistry;

    $after = $registry->upgrade($before, 1);

    expect($after)->toHaveKey('object_types/deal.yaml')
        ->and($after['object_types/deal.yaml'])->toBe($before['object_types/deal.yaml']);
});

it('keeps the key and the file of a version two team access rule unchanged through the upgrade', function (): void {
    $before = BundleTreeFixture::v2WithTeamAccessRule('core', 'deal', ($this->filterTree)());

    [$beforePath, $beforePayload] = ($this->ruleEntryOf)($before);

    $after = (new BundleSchemaMigratorRegistry)->upgrade($before, 2);

    [$afterPath, $afterPayload] = ($this->ruleEntryOf)($after);

    expect($afterPayload['key'])->toBe('core:deal')
        ->and($afterPayload)->toBe($beforePayload)
        ->and($afterPath)->toBe($beforePath)
        ->and($after['manifest.yaml'])->toBe(array_diff_key($before['manifest.yaml'], ['environment' => true]))
        ->and($after)->toHaveCount(count($before));
});

it('upgrades a version three tree to version four by dropping the environment and the personal data flag only', function (): void {
    $before = [
        ...BundleTreeFixture::v2WithTeamAccessRule('core', 'deal', ($this->filterTree)()),
        'manifest.yaml' => [
            ...BundleTreeFixture::v2WithTeamAccessRule('core', 'deal', ($this->filterTree)())['manifest.yaml'],
            'schema_version' => 3,
        ],
        'field-definitions/deal__amount.yaml' => [
            'key' => 'deal:amount',
            'is_required' => true,
            'is_personal_data' => true,
        ],
    ];

    $after = (new BundleSchemaMigratorRegistry)->upgrade($before, 3);

    expect($before['manifest.yaml'])->toHaveKey('environment')
        ->and($after['manifest.yaml'])->toBe(array_diff_key($before['manifest.yaml'], ['environment' => true]))
        ->and($after['field-definitions/deal__amount.yaml'])->toBe(['key' => 'deal:amount', 'is_required' => true])
        ->and(array_diff_key($after, ['manifest.yaml' => true, 'field-definitions/deal__amount.yaml' => true]))
        ->toBe(array_diff_key($before, ['manifest.yaml' => true, 'field-definitions/deal__amount.yaml' => true]));
});

it('passes a version four tree through upgrade unchanged as a no-op', function (): void {
    $tree = [
        'manifest.yaml' => [
            ...array_diff_key(BundleTreeFixture::v2WithTeamAccessRule('core', 'deal', ($this->filterTree)())['manifest.yaml'], ['environment' => true]),
            'schema_version' => 4,
        ],
        'team-record-access-rules/core__deal.yaml' => [
            'key' => 'core:deal',
            'is_active' => true,
            'inheritance' => 'intersect',
            'filter_definition' => ($this->filterTree)(),
        ],
    ];

    expect((new BundleSchemaMigratorRegistry)->upgrade($tree, 4))->toBe($tree);
});

it('chains a version one tree through two and three so it loses generated_at and keeps the team key', function (): void {
    $before = [
        ...BundleTreeFixture::v1(),
        'team-record-access-rules/core__deal.yaml' => [
            'key' => 'core:deal',
            'is_active' => true,
            'inheritance' => 'intersect',
            'filter_definition' => ($this->filterTree)(),
        ],
    ];

    $after = (new BundleSchemaMigratorRegistry)->upgrade($before, 1);

    [, $payload] = ($this->ruleEntryOf)($after);

    expect($after['manifest.yaml'])->not->toHaveKey('generated_at')
        ->and($payload['key'])->toBe('core:deal')
        ->and($after['object_types/deal.yaml'])->toBe($before['object_types/deal.yaml']);
});

it('applies migrators in ascending fromVersion order regardless of constructor argument order', function (): void {
    $registry = new BundleSchemaMigratorRegistry([
        ($this->tracingMigrator)(2, 'two-to-three'),
        ($this->tracingMigrator)(1, 'one-to-two'),
    ]);

    $result = $registry->upgrade(['manifest.yaml' => ['trace' => []]], 1);

    expect($result['manifest.yaml']['trace'])->toBe(['one-to-two', 'two-to-three']);
});

it('reports version one as current for an empty migrator chain', function (): void {
    $registry = new BundleSchemaMigratorRegistry([]);

    expect($registry->currentVersion())->toBe(1);
});

it('wires the real production chain through the constructor default with no container binding', function (): void {
    $registry = new BundleSchemaMigratorRegistry;

    expect($registry->currentVersion())->toBe(4);
});

it('rejects two migrators with the same fromVersion already at construction', function (): void {
    $first = ($this->noOpMigrator)(1);
    $second = ($this->noOpMigrator)(1);

    expect(fn () => new BundleSchemaMigratorRegistry([$first, $second]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a version newer than currently supported with a german message naming both versions', function (): void {
    $registry = new BundleSchemaMigratorRegistry;

    try {
        $registry->upgrade(BundleTreeFixture::v2(), 5);
    } catch (UnsupportedBundleSchemaVersionException $exception) {
        expect($exception->getMessage())->toContain('5')
            ->and($exception->getMessage())->toContain('4');

        return;
    }

    $this->fail('Expected an UnsupportedBundleSchemaVersionException to be thrown.');
});

it('rejects a version extracted as zero from a tree without a schema version key', function (): void {
    $registry = new BundleSchemaMigratorRegistry;
    $rawManifest = BundleTreeFixture::v1()['manifest.yaml'];
    unset($rawManifest['schema_version']);

    $extractedVersion = (int) ($rawManifest['schema_version'] ?? 0);

    expect(fn () => $registry->upgrade(BundleTreeFixture::v1(), $extractedVersion))
        ->toThrow(UnsupportedBundleSchemaVersionException::class);
});

it('rejects version zero passed directly as fromVersion', function (): void {
    $registry = new BundleSchemaMigratorRegistry;

    expect(fn () => $registry->upgrade(BundleTreeFixture::v1(), 0))
        ->toThrow(UnsupportedBundleSchemaVersionException::class);
});

it('rejects a negative version passed directly as fromVersion', function (): void {
    $registry = new BundleSchemaMigratorRegistry;

    expect(fn () => $registry->upgrade(BundleTreeFixture::v1(), -1))
        ->toThrow(UnsupportedBundleSchemaVersionException::class);
});

it('rejects a gap in the chain instead of silently skipping the missing link', function (): void {
    $registry = new BundleSchemaMigratorRegistry([
        ($this->noOpMigrator)(1),
        ($this->noOpMigrator)(3),
    ]);

    expect(fn () => $registry->upgrade(['manifest.yaml' => []], 1))
        ->toThrow(UnsupportedBundleSchemaVersionException::class);
});
