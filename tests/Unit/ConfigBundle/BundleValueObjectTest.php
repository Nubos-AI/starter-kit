<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->artifact = fn (ArtifactKind $kind, string $key, array $payload = [], array $dependsOn = []): BundleArtifact => new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: $payload === [] ? ['label' => $key] : $payload,
        dependsOn: $dependsOn,
    );

    $this->manifest = fn (int $schemaVersion = 1): BundleManifest => new BundleManifest(
        schemaVersion: $schemaVersion,
        sourceLabel: 'Sandbox of Acme',
        artifactCounts: ['object-types' => 2, 'roles' => 1],
    );
});

test('the same payload hashes to the same value across two constructions', function (): void {
    $payload = ['label' => 'Invoice', 'is_active' => true, 'position' => 3];

    $first = ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $payload);
    $second = ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $payload);

    expect($first->hash())->toBe($second->hash())
        ->and($first->hash())->toMatch('/^[0-9a-f]{64}$/');
});

test('changing a single payload value changes the hash', function (): void {
    $before = ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', ['label' => 'Invoice', 'position' => 3]);
    $after = ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', ['label' => 'Invoice', 'position' => 4]);

    expect($after->hash())->not->toBe($before->hash());
});

test('the same pairs in a different key order hash differently so nobody sorts the payload', function (): void {
    $ordered = ($this->artifact)(ArtifactKind::FieldDefinitions, 'amount', ['label' => 'Amount', 'position' => 1]);
    $shuffled = ($this->artifact)(ArtifactKind::FieldDefinitions, 'amount', ['position' => 1, 'label' => 'Amount']);

    expect($shuffled->hash())->not->toBe($ordered->hash());
});

test('a payload with an umlaut and a float that binary cannot hold exactly hashes to the frozen encoding contract', function (): void {
    $artifact = ($this->artifact)(ArtifactKind::Reports, 'audit', ['label' => 'Prüfung', 'ratio' => 0.1, 'count' => 3]);

    expect($artifact->hash())->toBe('491bf2047ef7055dbd65d25f97c05c5c96e4b248ff988135dbe5c83511dfb0b8');
});

test('the hash ignores the declared dependencies and covers the payload only', function (): void {
    $bare = ($this->artifact)(ArtifactKind::StageTransitions, 'won', ['label' => 'Won'], []);
    $dependent = ($this->artifact)(ArtifactKind::StageTransitions, 'won', ['label' => 'Won'], ['pipeline-stages:open']);

    expect($dependent->hash())->toBe($bare->hash())
        ->and($dependent->dependsOn)->toBe(['pipeline-stages:open']);
});

test('a bundle carrying the same kind and key twice is refused on construction', function (): void {
    $manifest = ($this->manifest)();

    expect(fn (): ConfigBundle => new ConfigBundle($manifest, [
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice'),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', ['label' => 'Second']),
    ]))->toThrow(InvalidArgumentException::class);
});

test('the same key under two kinds is accepted because the identifier carries the kind', function (): void {
    $bundle = new ConfigBundle(($this->manifest)(), [
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice'),
        ($this->artifact)(ArtifactKind::Reports, 'invoice'),
    ]);

    expect($bundle->find(ArtifactKind::ObjectTypes, 'invoice'))->toBeInstanceOf(BundleArtifact::class)
        ->and($bundle->find(ArtifactKind::Reports, 'invoice'))->toBeInstanceOf(BundleArtifact::class)
        ->and($bundle->find(ArtifactKind::ObjectTypes, 'invoice')?->kind)->toBe(ArtifactKind::ObjectTypes)
        ->and($bundle->find(ArtifactKind::Reports, 'invoice')?->kind)->toBe(ArtifactKind::Reports);
});

test('byKind returns every artifact of that kind and an empty list for a kind without artifacts', function (): void {
    $bundle = new ConfigBundle(($this->manifest)(), [
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice'),
        ($this->artifact)(ArtifactKind::Roles, 'admin'),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'contract'),
    ]);

    $keys = array_map(
        static fn (BundleArtifact $artifact): string => $artifact->key,
        $bundle->byKind(ArtifactKind::ObjectTypes),
    );

    expect($keys)->toEqualCanonicalizing(['invoice', 'contract'])
        ->and($bundle->byKind(ArtifactKind::Dashboards))->toBe([]);
});

test('find returns null for a key the bundle does not carry', function (): void {
    $bundle = new ConfigBundle(($this->manifest)(), [
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice'),
    ]);

    expect($bundle->find(ArtifactKind::ObjectTypes, 'ghost'))->toBeNull()
        ->and($bundle->find(ArtifactKind::Skills, 'invoice'))->toBeNull();
});

test('a bundle without any artifact constructs and answers every reader', function (): void {
    $bundle = new ConfigBundle(($this->manifest)(), []);

    expect($bundle->artifacts)->toBe([])
        ->and($bundle->byKind(ArtifactKind::ObjectTypes))->toBe([])
        ->and($bundle->find(ArtifactKind::ObjectTypes, 'invoice'))->toBeNull()
        ->and($bundle->manifest->schemaVersion)->toBe(1);
});

test('a manifest below schema version one is refused on construction', function (int $schemaVersion): void {
    expect(fn (): BundleManifest => ($this->manifest)($schemaVersion))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

test('schema version one is accepted and kept', function (): void {
    $manifest = ($this->manifest)(1);

    expect($manifest->schemaVersion)->toBe(1)
        ->and($manifest->sourceLabel)->toBe('Sandbox of Acme')
        ->and($manifest->artifactCounts)->toEqual(['object-types' => 2, 'roles' => 1]);
});

test('the manifest carries no generation timestamp so two exports of one state stay byte equal', function (): void {
    $manifest = ($this->manifest)();

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(BundleManifest::class))->getProperties(),
    );

    expect($properties)->not->toContain('generatedAt')
        ->and($properties)->not->toContain('generated_at')
        ->and($properties)->not->toContain('createdAt')
        ->and(array_keys($manifest->toArray()))->not->toContain('generatedAt')
        ->and(array_keys($manifest->toArray()))->not->toContain('generated_at')
        ->and(array_keys($manifest->toArray()))->not->toContain('created_at');
});

test('a manifest survives the round trip through its array form without loss', function (): void {
    $manifest = ($this->manifest)();

    $restored = BundleManifest::fromArray($manifest->toArray());

    expect($restored->schemaVersion)->toBe($manifest->schemaVersion)
        ->and($restored->sourceLabel)->toBe($manifest->sourceLabel)
        ->and($restored->artifactCounts)->toEqual($manifest->artifactCounts);
});

test('an artifact without a business key is refused on construction', function (): void {
    expect(fn (): BundleArtifact => new BundleArtifact(
        kind: ArtifactKind::Roles,
        key: '',
        payload: ['label' => 'Admin'],
    ))->toThrow(InvalidArgumentException::class);
});

test('a manifest array without a schema version is refused', function (): void {
    $manifest = ($this->manifest)()->toArray();
    unset($manifest['schema_version']);

    expect(fn (): BundleManifest => BundleManifest::fromArray($manifest))->toThrow(InvalidArgumentException::class);
});

test('a manifest array without a source label is refused', function (): void {
    $manifest = ($this->manifest)()->toArray();
    unset($manifest['source_label']);

    expect(fn (): BundleManifest => BundleManifest::fromArray($manifest))->toThrow(InvalidArgumentException::class);
});

test('a manifest array whose artifact counts are not a map is refused instead of coerced', function (): void {
    $manifest = ($this->manifest)()->toArray();
    $manifest['artifact_counts'] = 'kaputt';

    expect(fn (): BundleManifest => BundleManifest::fromArray($manifest))->toThrow(InvalidArgumentException::class);
});

test('a manifest array whose artifact count is not a number is refused', function (): void {
    $manifest = ($this->manifest)()->toArray();
    $manifest['artifact_counts'] = ['roles' => 'kaputt'];

    expect(fn (): BundleManifest => BundleManifest::fromArray($manifest))->toThrow(InvalidArgumentException::class);
});

test('a manifest array whose artifact counts arrive as a list is refused', function (): void {
    $manifest = ($this->manifest)()->toArray();
    $manifest['artifact_counts'] = [5, 6];

    expect(fn (): BundleManifest => BundleManifest::fromArray($manifest))->toThrow(InvalidArgumentException::class);
});

test('an artifact refuses a write to its properties', function (): void {
    $artifact = ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice');

    expect(fn (): mixed => $artifact->key = 'tampered')->toThrow(Error::class);
});

test('a manifest refuses a write to its properties', function (): void {
    $manifest = ($this->manifest)();

    expect(fn (): mixed => $manifest->schemaVersion = 99)->toThrow(Error::class);
});

test('a bundle refuses a write to its properties', function (): void {
    $bundle = new ConfigBundle(($this->manifest)(), []);

    expect(fn (): mixed => $bundle->artifacts = [])->toThrow(Error::class);
});

test('the artifact kinds are exactly the bundle directories the decision names', function (): void {
    $expected = [
        'object-types',
        'field-groups',
        'field-definitions',
        'relationship-types',
        'pipelines',
        'pipeline-stages',
        'stage-transitions',
        'transition-gates',
        'field-dependencies',
        'merge-rules',
        'reminder-types',
        'roles',
        'role-permissions',
        'field-permissions',
        'team-record-access-rules',
        'automations',
        'automation-templates',
        'notification-rules',
        'notification-type-defaults',
        'webhook-subscriptions',
        'reports',
        'dashboards',
        'dashboard-widgets',
        'goals',
        'segments',
        'document-templates',
        'export-field-presets',
        'import-mapping-presets',
        'aging-rules',
        'skills',
    ];

    $actual = array_map(
        static fn (ArtifactKind $kind): string => $kind->value,
        ArtifactKind::cases(),
    );

    expect($actual)->toEqualCanonicalizing($expected);
});

test('dashboard widgets travel with the bundle while person bound and derived artifacts stay out', function (): void {
    $values = array_map(
        static fn (ArtifactKind $kind): string => $kind->value,
        ArtifactKind::cases(),
    );

    expect($values)->toContain('dashboard-widgets')
        ->and($values)->not->toContain('permission-overrides')
        ->and($values)->not->toContain('goal-periods');
});

test('the canonical artifact identifier joins kind and key with a colon', function (): void {
    expect(ArtifactKind::ObjectTypes->identifierFor('invoice'))->toBe('object-types:invoice')
        ->and(ArtifactKind::DashboardWidgets->identifierFor('revenue-tile'))->toBe('dashboard-widgets:revenue-tile')
        ->and(ArtifactKind::Roles->identifierFor('admin'))->toBe('roles:admin');
});
