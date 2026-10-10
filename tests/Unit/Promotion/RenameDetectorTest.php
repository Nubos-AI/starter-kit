<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\RenameHint;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Support\Promotion\RenameDetector;

beforeEach(function (): void {
    $this->manifest = fn (string $sourceLabel): BundleManifest => new BundleManifest(
        schemaVersion: 1,
        sourceLabel: "Bundle of {$sourceLabel}",
        artifactCounts: [],
    );

    $this->artifact = fn (ArtifactKind $kind, string $key, array $payload = [], array $dependsOn = []): BundleArtifact => new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: ['key' => $key] + $payload,
        dependsOn: $dependsOn,
    );

    $this->sandbox = fn (array $artifacts): ConfigBundle => new ConfigBundle(($this->manifest)('sandbox'), $artifacts);

    $this->production = fn (array $artifacts): ConfigBundle => new ConfigBundle(($this->manifest)('production'), $artifacts);

    $this->entry = fn (ArtifactKind $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: $state === DiffState::Removed ? null : "source-{$key}",
        targetHash: $state === DiffState::Added ? null : "target-{$key}",
        baselineHash: $state === DiffState::Added ? null : "baseline-{$key}",
        changedPaths: $state === DiffState::Modified ? ['label'] : [],
    );

    $this->detect = fn (BundleDiff $diff, ConfigBundle $source, ConfigBundle $target): array => (new RenameDetector)->detect($diff, $source, $target);

    $this->hintRows = fn (array $hints): array => array_map(
        static fn (RenameHint $hint): array => [
            'kind' => $hint->kind->value,
            'fromKey' => $hint->fromKey,
            'toKey' => $hint->toKey,
            'exact' => $hint->exact,
        ],
        $hints,
    );

    $this->diffRows = fn (BundleDiff $diff): array => array_map(
        static fn (ArtifactDiff $entry): array => [
            'kind' => $entry->kind->value,
            'key' => $entry->key,
            'state' => $entry->state->value,
            'sourceHash' => $entry->sourceHash,
            'targetHash' => $entry->targetHash,
            'baselineHash' => $entry->baselineHash,
            'changedPaths' => $entry->changedPaths,
        ],
        $diff->diffs,
    );
});

test('the detector reports indistinguishability rather than an established rename when a removal and an addition of one kind share their residual payload', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot', 'position' => 1]),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot', 'position' => 1]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    $hints = ($this->detect)($diff, $source, $target);

    expect($hints)->toHaveCount(1)
        ->and($hints[0])->toBeInstanceOf(RenameHint::class)
        ->and($hints[0]->kind)->toBe(ArtifactKind::ObjectTypes)
        ->and($hints[0]->fromKey)->toBe('quote')
        ->and($hints[0]->toKey)->toBe('offer')
        ->and($hints[0]->exact)->toBeTrue();
});

test('two renames are reported in the order of the added entries of the diff', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'customer', ['label' => 'Kunde']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'client', ['label' => 'Kunde']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'customer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'client', 'toKey' => 'customer', 'exact' => true],
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('a rename is still reported when the two artifacts declare different dependencies', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'offer:amount', ['label' => 'Betrag'], ['field-groups:billing']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'offer:total', ['label' => 'Betrag'], []),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::FieldDefinitions, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions, 'offer:total', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'field-definitions', 'fromKey' => 'offer:total', 'toKey' => 'offer:amount', 'exact' => true],
    ]);
});

test('a kind whose payload carries nothing beyond the business key yields no hint at all', function (ArtifactKind $kind, string $removedKey, string $addedKey): void {
    $source = ($this->sandbox)([($this->artifact)($kind, $addedKey)]);
    $target = ($this->production)([($this->artifact)($kind, $removedKey)]);

    $diff = new BundleDiff([
        ($this->entry)($kind, $addedKey, DiffState::Added),
        ($this->entry)($kind, $removedKey, DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
})->with([
    'skills' => [ArtifactKind::Skills, 'negotiation', 'objection-handling'],
    'role permissions' => [ArtifactKind::RolePermissions, 'admin:records.view', 'manager:records.view'],
]);

test('the guard against an empty residual payload follows the payload instead of a hard coded list of kinds', function (): void {
    $source = ($this->sandbox)([($this->artifact)(ArtifactKind::ObjectTypes, 'offer')]);
    $target = ($this->production)([($this->artifact)(ArtifactKind::ObjectTypes, 'quote')]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('two removals sharing one residual payload leave the single addition without a hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'estimate', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'estimate', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('one removal explains exactly one of two identical additions, the first one in diff order', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'proposal', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'proposal', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'proposal', 'exact' => true],
    ]);
});

test('a diff without additions, a diff without removals and an empty diff each yield no hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $withoutAdditions = new BundleDiff([($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed)]);
    $withoutRemovals = new BundleDiff([($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added)]);
    $empty = new BundleDiff([]);

    expect(($this->detect)($withoutAdditions, $source, $target))->toBe([])
        ->and(($this->detect)($withoutRemovals, $source, $target))->toBe([])
        ->and(($this->detect)($empty, $source, $target))->toBe([]);
});

test('a diff entry whose artifact is missing from its bundle is skipped silently while the neighbouring pair still yields its hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'ghost', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'phantom', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('only added and removed entries take part while modified unchanged and conflicted ones never do', function (): void {
    $shared = ['label' => 'Angebot'];

    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'contract', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'report', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', $shared),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'contract', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'report', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', $shared),
    ]);

    $withoutRemoval = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'contract', DiffState::Modified),
        ($this->entry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Unchanged),
        ($this->entry)(ArtifactKind::ObjectTypes, 'report', DiffState::Conflicted),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
    ]);

    $withoutAddition = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'contract', DiffState::Modified),
        ($this->entry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Unchanged),
        ($this->entry)(ArtifactKind::ObjectTypes, 'report', DiffState::Conflicted),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($withoutRemoval, $source, $target))->toBe([])
        ->and(($this->detect)($withoutAddition, $source, $target))->toBe([]);
});

test('a removal and an addition carrying different payloads are not reported', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Auftrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('an identical residual payload under two kinds is never paired', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::Reports, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', ['label' => 'Buchung']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::Reports, 'ledger', ['label' => 'Buchung']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::Reports, 'ledger', DiffState::Removed),
        ($this->entry)(ArtifactKind::Reports, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('a payload differing only in the type of a value is never paired', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['position' => 3]),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'customer', ['is_active' => null]),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['position' => '3']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'client', ['is_active' => false]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'customer', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('the same payload pairs in another key order yield no hint because the detector shares exactly one definition of equality with hash and reports indistinguishability rather than an established rename', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'offer:amount', ['position' => 1, 'label' => 'Betrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'offer:total', ['label' => 'Betrag', 'position' => 1]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::FieldDefinitions, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions, 'offer:total', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('swapping the two bundles yields no hint because additions are looked up in the source and removals in the target', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toHaveCount(1)
        ->and(($this->detect)($diff, $target, $source))->toBe([]);
});

test('a cascading child rename stays unreported by design because the detector reports indistinguishability rather than an established rename', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'offer:amount', ['object_type_key' => 'offer', 'label' => 'Betrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::FieldDefinitions, 'quote:amount', ['object_type_key' => 'quote', 'label' => 'Betrag']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::FieldDefinitions, 'quote:amount', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('the diff carries the same entries in the same order after the detector has run', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::Roles, 'admin', ['authority' => 90]),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::Roles, 'admin', ['authority' => 80]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::Roles, 'admin', DiffState::Modified),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
    ]);

    $before = ($this->diffRows)($diff);

    $hints = ($this->detect)($diff, $source, $target);

    expect($hints)->toHaveCount(1)
        ->and(($this->diffRows)($diff))->toBe($before)
        ->and($diff->diffs)->toHaveCount(3);
});

test('two consecutive runs of one detector on the same input return the same hints', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'customer', ['label' => 'Kunde']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes, 'client', ['label' => 'Kunde']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes, 'customer', DiffState::Added),
    ]);

    $detector = new RenameDetector;

    $first = ($this->hintRows)($detector->detect($diff, $source, $target));
    $second = ($this->hintRows)($detector->detect($diff, $source, $target));

    expect($first)->toHaveCount(2)
        ->and($second)->toBe($first);
});

test('a rename hint carries exactly the four fields kind fromKey toKey and exact in that order', function (): void {
    $names = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(RenameHint::class))->getProperties(),
    );

    $hint = new RenameHint(
        kind: ArtifactKind::ObjectTypes,
        fromKey: 'quote',
        toKey: 'offer',
        exact: true,
    );

    expect($names)->toBe(['kind', 'fromKey', 'toKey', 'exact'])
        ->and((string) (new ReflectionClass(RenameHint::class))->getProperty('exact')->getType())->toBe('bool')
        ->and($hint->kind)->toBe(ArtifactKind::ObjectTypes)
        ->and($hint->fromKey)->toBe('quote')
        ->and($hint->toKey)->toBe('offer')
        ->and($hint->exact)->toBeTrue();
});

test('a rename hint refuses a write to its properties', function (): void {
    $hint = new RenameHint(
        kind: ArtifactKind::ObjectTypes,
        fromKey: 'quote',
        toKey: 'offer',
        exact: true,
    );

    expect(fn (): mixed => $hint->toKey = 'tampered')->toThrow(Error::class);
});
