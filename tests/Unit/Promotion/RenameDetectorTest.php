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

    $this->artifact = fn (string $kind, string $key, array $payload = [], array $dependsOn = []): BundleArtifact => new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: ['key' => $key] + $payload,
        dependsOn: $dependsOn,
    );

    $this->sandbox = fn (array $artifacts): ConfigBundle => new ConfigBundle(($this->manifest)('sandbox'), $artifacts);

    $this->production = fn (array $artifacts): ConfigBundle => new ConfigBundle(($this->manifest)('production'), $artifacts);

    $this->entry = fn (string $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
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
            'kind' => $hint->kind,
            'fromKey' => $hint->fromKey,
            'toKey' => $hint->toKey,
            'exact' => $hint->exact,
        ],
        $hints,
    );
});

test('the detector reports indistinguishability rather than an established rename when a removal and an addition of one kind share their residual payload', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot', 'position' => 1]),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot', 'position' => 1]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
    ]);

    $hints = ($this->detect)($diff, $source, $target);

    expect($hints)->toHaveCount(1)
        ->and($hints[0])->toBeInstanceOf(RenameHint::class)
        ->and($hints[0]->kind)->toBe(ArtifactKind::ObjectTypes->value)
        ->and($hints[0]->fromKey)->toBe('quote')
        ->and($hints[0]->toKey)->toBe('offer')
        ->and($hints[0]->exact)->toBeTrue();
});

test('two renames are reported in the order of the added entries of the diff', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'customer', ['label' => 'Kunde']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'client', ['label' => 'Kunde']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'customer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'client', 'toKey' => 'customer', 'exact' => true],
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('a rename is still reported when the two artifacts declare different dependencies', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'offer:amount', ['label' => 'Betrag'], ['field-groups:billing']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'offer:total', ['label' => 'Betrag'], []),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'offer:total', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'field-definitions', 'fromKey' => 'offer:total', 'toKey' => 'offer:amount', 'exact' => true],
    ]);
});

test('a kind whose payload carries nothing beyond the business key yields no hint at all', function (string $kind, string $removedKey, string $addedKey): void {
    $source = ($this->sandbox)([($this->artifact)($kind, $addedKey)]);
    $target = ($this->production)([($this->artifact)($kind, $removedKey)]);

    $diff = new BundleDiff([
        ($this->entry)($kind, $addedKey, DiffState::Added),
        ($this->entry)($kind, $removedKey, DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
})->with([
    'skills' => [ArtifactKind::Skills->value, 'negotiation', 'objection-handling'],
    'role permissions' => [ArtifactKind::RolePermissions->value, 'admin:records.view', 'manager:records.view'],
    'object types, because the guard follows the payload instead of a hard coded list of kinds' => [ArtifactKind::ObjectTypes->value, 'quote', 'offer'],
]);

test('two removals sharing one residual payload leave the single addition without a hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'estimate', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'estimate', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('one removal explains exactly one of two identical additions, the first one in diff order', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'proposal', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'proposal', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'proposal', 'exact' => true],
    ]);
});

test('a diff without additions, a diff without removals and an empty diff each yield no hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $withoutAdditions = new BundleDiff([($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed)]);
    $withoutRemovals = new BundleDiff([($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added)]);
    $empty = new BundleDiff([]);

    expect(($this->detect)($withoutAdditions, $source, $target))->toBe([])
        ->and(($this->detect)($withoutRemovals, $source, $target))->toBe([])
        ->and(($this->detect)($empty, $source, $target))->toBe([]);
});

test('a diff entry whose artifact is missing from its bundle is skipped silently while the neighbouring pair still yields its hint', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'ghost', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'phantom', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('only added and removed entries take part while modified unchanged and conflicted ones never do', function (): void {
    $shared = ['label' => 'Angebot'];

    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'contract', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'report', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', $shared),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'contract', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'report', $shared),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', $shared),
    ]);

    $withoutRemoval = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'contract', DiffState::Modified),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Unchanged),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'report', DiffState::Conflicted),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
    ]);

    $withoutAddition = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'contract', DiffState::Modified),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Unchanged),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'report', DiffState::Conflicted),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($withoutRemoval, $source, $target))->toBe([])
        ->and(($this->detect)($withoutAddition, $source, $target))->toBe([]);
});

test('a removal and an addition carrying different payloads are not reported', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Auftrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('an identical residual payload under two kinds is never paired', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::Reports->value, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', ['label' => 'Buchung']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::Reports->value, 'ledger', ['label' => 'Buchung']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::Reports->value, 'ledger', DiffState::Removed),
        ($this->entry)(ArtifactKind::Reports->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('a payload differing only in the type of a value is never paired', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['position' => 3]),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'customer', ['is_active' => null]),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['position' => '3']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'client', ['is_active' => false]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'customer', DiffState::Added),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('the same payload pairs in another key order yield no hint because the detector shares exactly one definition of equality with hash and reports indistinguishability rather than an established rename', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'offer:amount', ['position' => 1, 'label' => 'Betrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'offer:total', ['label' => 'Betrag', 'position' => 1]),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'offer:total', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toBe([]);
});

test('swapping the two bundles yields no hint because additions are looked up in the source and removals in the target', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
    ]);

    expect(($this->detect)($diff, $source, $target))->toHaveCount(1)
        ->and(($this->detect)($diff, $target, $source))->toBe([]);
});

test('a cascading child rename stays unreported by design because the detector reports indistinguishability rather than an established rename', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'offer:amount', ['object_type_key' => 'offer', 'label' => 'Betrag']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'quote:amount', ['object_type_key' => 'quote', 'label' => 'Betrag']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'offer:amount', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::FieldDefinitions->value, 'quote:amount', DiffState::Removed),
    ]);

    $rows = ($this->hintRows)(($this->detect)($diff, $source, $target));

    expect($rows)->toBe([
        ['kind' => 'object-types', 'fromKey' => 'quote', 'toKey' => 'offer', 'exact' => true],
    ]);
});

test('two consecutive runs of one detector on the same input return the same hints', function (): void {
    $source = ($this->sandbox)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'offer', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'customer', ['label' => 'Kunde']),
    ]);

    $target = ($this->production)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'quote', ['label' => 'Angebot']),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'client', ['label' => 'Kunde']),
    ]);

    $diff = new BundleDiff([
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'quote', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'client', DiffState::Removed),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'offer', DiffState::Added),
        ($this->entry)(ArtifactKind::ObjectTypes->value, 'customer', DiffState::Added),
    ]);

    $detector = new RenameDetector;

    $first = ($this->hintRows)($detector->detect($diff, $source, $target));
    $second = ($this->hintRows)($detector->detect($diff, $source, $target));

    expect($first)->toHaveCount(2)
        ->and($second)->toBe($first);
});
