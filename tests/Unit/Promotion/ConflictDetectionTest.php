<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Support\Promotion\BundleDiffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\InMemoryPromotionBaselineStore;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->baselines = new InMemoryPromotionBaselineStore;

    $this->counterpart = fn (): string => 'sandbox:'.(string) Str::ulid();

    $this->artifact = fn (ArtifactKind $kind, string $key, array $payload): BundleArtifact => new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: $payload,
    );

    $this->bundleOf = fn (array $artifacts): ConfigBundle => new ConfigBundle(
        manifest: new BundleManifest(
            schemaVersion: 1,
            sourceLabel: 'Konfliktfutter',
            artifactCounts: [],
        ),
        artifacts: array_values($artifacts),
    );

    $this->hashOf = fn (ArtifactKind $kind, string $key, array $payload): string => (new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: $payload,
    ))->hash();

    $this->rememberBaseline = function (string $counterpartKey, array $hashes): void {
        $this->baselines->remember($counterpartKey, $hashes);
    };

    $this->payload = fn (string $label, string $fieldKey): array => [
        'label' => $label,
        'definition' => [
            'trigger' => [
                'field_key' => $fieldKey,
                'operator' => 'gt',
            ],
        ],
    ];

    $this->differ = fn (): BundleDiffer => new BundleDiffer($this->baselines);

    $this->entryFor = function (BundleDiff $diff, ArtifactKind $kind, string $key): ArtifactDiff {
        foreach ($diff->diffs as $entry) {
            if ($entry->kind === $kind && $entry->key === $key) {
                return $entry;
            }
        }

        Assert::fail("The comparison carries no entry for {$kind->identifierFor($key)}.");
    };

    $this->selectionOf = fn (array $pairs): PromotionSelection => array_reduce(
        $pairs,
        static fn (PromotionSelection $carry, array $pair): PromotionSelection => $carry->withAdded($pair[0], $pair[1]),
        new PromotionSelection([]),
    );

    $this->decisionFor = fn (ArtifactKind $kind, string $key): ConflictDecision => new ConflictDecision(
        kind: $kind,
        key: $key,
        resolution: ConflictResolution::TakeSource,
        decidedById: '01JBQ0Z6Q9K7X3M2N4P5R6S7T8',
        decidedAt: CarbonImmutable::parse('2026-03-04T10:15:30+00:00'),
    );

    $this->mixedComparison = function (): BundleDiff {
        $counterpart = ($this->counterpart)();
        $baselineVersion = ($this->payload)('Entwurf', 'net');
        $firstVersion = ($this->payload)('Rechnung', 'amount');
        $secondVersion = ($this->payload)('Beleg', 'total');

        ($this->rememberBaseline)($counterpart, [
            ArtifactKind::ObjectTypes->identifierFor('invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes, 'invoice', $baselineVersion),
            ArtifactKind::Roles->identifierFor('admin') => ($this->hashOf)(ArtifactKind::Roles, 'admin', $firstVersion),
        ]);

        $source = ($this->bundleOf)([
            ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $secondVersion),
            ($this->artifact)(ArtifactKind::Roles, 'admin', $secondVersion),
            ($this->artifact)(ArtifactKind::Reports, 'pipeline', $firstVersion),
            ($this->artifact)(ArtifactKind::Segments, 'north', $secondVersion),
        ]);

        $target = ($this->bundleOf)([
            ($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $firstVersion),
            ($this->artifact)(ArtifactKind::Roles, 'admin', $firstVersion),
            ($this->artifact)(ArtifactKind::Reports, 'pipeline', $firstVersion),
            ($this->artifact)(ArtifactKind::Segments, 'north', $firstVersion),
        ]);

        return ($this->differ)()->diff($source, $target, $counterpart);
    };
});

it('reports an artifact that both sides moved since the last sync as a conflict', function (): void {
    $counterpart = ($this->counterpart)();
    $baselineVersion = ($this->payload)('Entwurf', 'net');
    $sourceVersion = ($this->payload)('Beleg', 'total');
    $targetVersion = ($this->payload)('Rechnung', 'amount');
    $baselineHash = ($this->hashOf)(ArtifactKind::ObjectTypes, 'invoice', $baselineVersion);

    ($this->rememberBaseline)($counterpart, [
        ArtifactKind::ObjectTypes->identifierFor('invoice') => $baselineHash,
    ]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff(
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $sourceVersion)]),
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $targetVersion)]),
            $counterpart,
        ),
        ArtifactKind::ObjectTypes,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Conflicted)
        ->and($entry->baselineHash)->toBe($baselineHash)
        ->and($entry->changedPaths)->not->toBe([])
        ->and($entry->changedPaths)->toContain('label');
});

it('reports a conflict when only the target moved because a promotion would overwrite that change', function (): void {
    $counterpart = ($this->counterpart)();
    $sourceVersion = ($this->payload)('Rechnung', 'amount');
    $targetVersion = ($this->payload)('Beleg', 'total');
    $baselineHash = ($this->hashOf)(ArtifactKind::ObjectTypes, 'invoice', $sourceVersion);

    ($this->rememberBaseline)($counterpart, [
        ArtifactKind::ObjectTypes->identifierFor('invoice') => $baselineHash,
    ]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff(
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $sourceVersion)]),
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $targetVersion)]),
            $counterpart,
        ),
        ArtifactKind::ObjectTypes,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Conflicted)
        ->and($entry->sourceHash)->toBe($baselineHash)
        ->and($entry->baselineHash)->toBe($baselineHash)
        ->and($entry->changedPaths)->toBe(['definition.trigger.field_key', 'label']);
});

it('reports a conflict and not a modification when the two sides differ and no baseline exists at all', function (): void {
    $sourceVersion = ($this->payload)('Beleg', 'total');
    $targetVersion = ($this->payload)('Rechnung', 'amount');

    $entry = ($this->entryFor)(
        ($this->differ)()->diff(
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $sourceVersion)]),
            ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $targetVersion)]),
            ($this->counterpart)(),
        ),
        ArtifactKind::ObjectTypes,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Conflicted)
        ->and($entry->state)->not->toBe(DiffState::Modified)
        ->and($entry->baselineHash)->toBeNull()
        ->and($entry->changedPaths)->not->toBe([]);
});

it('judges the same artifact differently against two counterparts that carry different baselines', function (): void {
    $sandboxA = ($this->counterpart)();
    $sandboxB = ($this->counterpart)();
    $earlierVersion = ($this->payload)('Entwurf', 'net');
    $targetVersion = ($this->payload)('Rechnung', 'amount');
    $sourceVersion = ($this->payload)('Beleg', 'total');

    ($this->rememberBaseline)($sandboxA, [
        ArtifactKind::ObjectTypes->identifierFor('invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes, 'invoice', $targetVersion),
    ]);

    ($this->rememberBaseline)($sandboxB, [
        ArtifactKind::ObjectTypes->identifierFor('invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes, 'invoice', $earlierVersion),
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $sourceVersion)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes, 'invoice', $targetVersion)]);
    $differ = ($this->differ)();

    $againstA = ($this->entryFor)($differ->diff($source, $target, $sandboxA), ArtifactKind::ObjectTypes, 'invoice');
    $againstB = ($this->entryFor)($differ->diff($source, $target, $sandboxB), ArtifactKind::ObjectTypes, 'invoice');

    expect($againstA->state)->toBe(DiffState::Modified)
        ->and($againstB->state)->toBe(DiffState::Conflicted)
        ->and($againstA->baselineHash)->not->toBe($againstB->baselineHash);
});

it('lists exactly the conflicted artifacts of a mixed comparison', function (): void {
    $diff = ($this->mixedComparison)();

    $conflicted = array_map(
        static fn (ArtifactDiff $entry): string => $entry->kind->identifierFor($entry->key),
        $diff->conflicts(),
    );

    expect($conflicted)->toEqualCanonicalizing([
        ArtifactKind::ObjectTypes->identifierFor('invoice'),
        ArtifactKind::Segments->identifierFor('north'),
    ])
        ->and(($this->entryFor)($diff, ArtifactKind::Roles, 'admin')->state)->toBe(DiffState::Modified)
        ->and(($this->entryFor)($diff, ArtifactKind::Reports, 'pipeline')->state)->toBe(DiffState::Unchanged);
});

it('leaves a selection open when the only conflicts sit outside it', function (): void {
    $diff = ($this->mixedComparison)();

    $selection = ($this->selectionOf)([[ArtifactKind::Roles, 'admin']]);

    expect($diff->conflicts())->not->toBe([])
        ->and($diff->hasUndecidedConflicts($selection, []))->toBeFalse();
});

it('blocks a selection that contains an undecided conflict', function (): void {
    $diff = ($this->mixedComparison)();

    $selection = ($this->selectionOf)([
        [ArtifactKind::Roles, 'admin'],
        [ArtifactKind::ObjectTypes, 'invoice'],
    ]);

    expect($diff->hasUndecidedConflicts($selection, []))->toBeTrue();
});

it('unblocks the selection once the conflict inside it carries a decision', function (): void {
    $diff = ($this->mixedComparison)();

    $selection = ($this->selectionOf)([
        [ArtifactKind::Roles, 'admin'],
        [ArtifactKind::ObjectTypes, 'invoice'],
    ]);

    $decisions = [($this->decisionFor)(ArtifactKind::ObjectTypes, 'invoice')];

    expect($diff->hasUndecidedConflicts($selection, $decisions))->toBeFalse()
        ->and($diff->hasUndecidedConflicts($selection, []))->toBeTrue();
});
