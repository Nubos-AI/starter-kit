<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Support\ConfigBundle\ArtifactKindRegistry;
use App\Support\Promotion\BundleDiffer;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\InMemoryPromotionBaselineStore;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->baselines = new InMemoryPromotionBaselineStore;

    $this->counterpart = fn (): string => 'tenant:'.(string) Str::ulid();

    $this->artifact = fn (string $kind, string $key, array $payload): BundleArtifact => new BundleArtifact(
        kind: $kind,
        key: $key,
        payload: $payload,
    );

    $this->bundleOf = fn (array $artifacts): ConfigBundle => new ConfigBundle(
        manifest: new BundleManifest(
            schemaVersion: 1,
            sourceLabel: 'Vergleichsfutter',
            artifactCounts: [],
        ),
        artifacts: array_values($artifacts),
    );

    $this->hashOf = fn (string $kind, string $key, array $payload): string => (new BundleArtifact(
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

    $this->entryFor = function (BundleDiff $diff, string $kind, string $key): ArtifactDiff {
        foreach ($diff->diffs as $entry) {
            if ($entry->kind === $kind && $entry->key === $key) {
                return $entry;
            }
        }

        Assert::fail("The comparison carries no entry for {ArtifactKindRegistry::identifierFor($kind, $key)}.");
    };

    $this->identifiersOf = fn (BundleDiff $diff): array => array_map(
        static fn (ArtifactDiff $entry): string => ArtifactKindRegistry::identifierFor($entry->kind, $entry->key),
        $diff->diffs,
    );
});

it('reports an artifact that only the source carries as an addition', function (): void {
    $payload = ($this->payload)('Rechnung', 'amount');
    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload)]);
    $target = ($this->bundleOf)([]);

    $diff = ($this->differ)()->diff($source, $target, ($this->counterpart)());
    $entry = ($this->entryFor)($diff, ArtifactKind::ObjectTypes->value, 'invoice');

    expect($entry->state)->toBe(DiffState::Added)
        ->and($entry->sourceHash)->toBe(($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $payload))
        ->and($entry->targetHash)->toBeNull()
        ->and($entry->baselineHash)->toBeNull()
        ->and($entry->changedPaths)->toBe([]);
});

it('keeps calling a source only artifact an addition even when a baseline remembers it', function (): void {
    $counterpart = ($this->counterpart)();
    $payload = ($this->payload)('Rechnung', 'amount');
    $baselineHash = ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', ($this->payload)('Rechnung alt', 'amount'));

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => $baselineHash,
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload)]);
    $target = ($this->bundleOf)([]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Added)
        ->and($entry->targetHash)->toBeNull()
        ->and($entry->baselineHash)->toBe($baselineHash)
        ->and($entry->changedPaths)->toBe([]);
});

it('reports an artifact that only the target carries as a removal when a baseline knows it', function (): void {
    $counterpart = ($this->counterpart)();
    $payload = ($this->payload)('Rechnung', 'amount');
    $targetHash = ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $payload);

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => $targetHash,
    ]);

    $source = ($this->bundleOf)([]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Removed)
        ->and($entry->sourceHash)->toBeNull()
        ->and($entry->targetHash)->toBe($targetHash)
        ->and($entry->baselineHash)->toBe($targetHash)
        ->and($entry->changedPaths)->toBe([]);
});

it('leaves every artifact that only the target carries without a baseline untouched instead of proposing its removal', function (): void {
    $payload = ($this->payload)('Rechnung', 'amount');
    $source = ($this->bundleOf)([]);
    $target = ($this->bundleOf)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload),
        ($this->artifact)(ArtifactKind::Roles->value, 'admin', $payload),
    ]);

    $diff = ($this->differ)()->diff($source, $target, ($this->counterpart)());

    $states = array_map(
        static fn (ArtifactDiff $entry): DiffState => $entry->state,
        $diff->diffs,
    );

    expect($diff->diffs)->toHaveCount(2)
        ->and($states)->toBe([DiffState::Unchanged, DiffState::Unchanged]);

    foreach ([[ArtifactKind::ObjectTypes->value, 'invoice'], [ArtifactKind::Roles->value, 'admin']] as [$kind, $key]) {
        $entry = ($this->entryFor)($diff, $kind, $key);

        expect($entry->state)->not->toBe(DiffState::Removed)
            ->and($entry->sourceHash)->toBeNull()
            ->and($entry->baselineHash)->toBeNull()
            ->and($entry->changedPaths)->toBe([]);
    }
});

it('calls two identical payloads unchanged and compares no paths at all', function (): void {
    $payload = ($this->payload)('Rechnung', 'amount');
    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $payload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, ($this->counterpart)()),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Unchanged)
        ->and($entry->changedPaths)->toBe([])
        ->and($entry->sourceHash)->toBe($entry->targetHash);
});

it('calls an artifact modified when only the source moved away from the baseline and names every changed path', function (): void {
    $counterpart = ($this->counterpart)();
    $targetPayload = ($this->payload)('Rechnung', 'amount');
    $sourcePayload = ($this->payload)('Beleg', 'total');
    $baselineHash = ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload);

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => $baselineHash,
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $sourcePayload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Modified)
        ->and($entry->baselineHash)->toBe($baselineHash)
        ->and($entry->targetHash)->toBe($baselineHash)
        ->and($entry->sourceHash)->toBe(($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $sourcePayload))
        ->and($entry->changedPaths)->toBe(['definition.trigger.field_key', 'label']);
});

it('names a changed list entry with its index and its leaf key', function (): void {
    $counterpart = ($this->counterpart)();
    $targetPayload = ['steps' => [['action' => 'notify'], ['action' => 'assign']]];
    $sourcePayload = ['steps' => [['action' => 'notify'], ['action' => 'escalate']]];

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::NotificationRules->value, 'escalation') => ($this->hashOf)(ArtifactKind::NotificationRules->value, 'escalation', $targetPayload),
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::NotificationRules->value, 'escalation', $sourcePayload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::NotificationRules->value, 'escalation', $targetPayload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::NotificationRules->value,
        'escalation',
    );

    expect($entry->state)->toBe(DiffState::Modified)
        ->and($entry->changedPaths)->toBe(['steps[1].action']);
});

it('names a key that only one side carries', function (): void {
    $counterpart = ($this->counterpart)();
    $targetPayload = ['label' => 'Rechnung'];
    $sourcePayload = ['label' => 'Rechnung', 'extra' => 'zusatz'];

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload),
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $sourcePayload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Modified)
        ->and($entry->changedPaths)->toBe(['extra']);
});

it('tells an explicit null apart from a missing key', function (): void {
    $counterpart = ($this->counterpart)();
    $targetPayload = ['label' => 'Rechnung'];
    $sourcePayload = ['label' => 'Rechnung', 'owner' => null];

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload),
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $sourcePayload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->state)->toBe(DiffState::Modified)
        ->and($entry->changedPaths)->toBe(['owner']);
});

it('names only the changed leaf and neither its parent nor an untouched sibling branch', function (): void {
    $counterpart = ($this->counterpart)();
    $targetPayload = [
        'definition' => [
            'trigger' => ['field_key' => 'amount', 'operator' => 'gt'],
            'window' => ['unit' => 'days', 'amount' => 7],
        ],
    ];
    $sourcePayload = [
        'definition' => [
            'trigger' => ['field_key' => 'total', 'operator' => 'gt'],
            'window' => ['unit' => 'days', 'amount' => 7],
        ],
    ];

    ($this->rememberBaseline)($counterpart, [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload),
    ]);

    $source = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $sourcePayload)]);
    $target = ($this->bundleOf)([($this->artifact)(ArtifactKind::ObjectTypes->value, 'invoice', $targetPayload)]);

    $entry = ($this->entryFor)(
        ($this->differ)()->diff($source, $target, $counterpart),
        ArtifactKind::ObjectTypes->value,
        'invoice',
    );

    expect($entry->changedPaths)->toBe(['definition.trigger.field_key']);
});

it('orders the result by artifact kind and then by business key regardless of the construction order', function (): void {
    $counterpart = ($this->counterpart)();
    $payload = ($this->payload)('Rechnung', 'amount');

    $ten = ($this->artifact)(ArtifactKind::ObjectTypes->value, '10', $payload);
    $nine = ($this->artifact)(ArtifactKind::ObjectTypes->value, '9', $payload);
    $amount = ($this->artifact)(ArtifactKind::FieldDefinitions->value, 'amount', $payload);
    $admin = ($this->artifact)(ArtifactKind::Roles->value, 'admin', $payload);

    $target = ($this->bundleOf)([]);

    $first = ($this->differ)()->diff(($this->bundleOf)([$ten, $nine, $amount, $admin]), $target, $counterpart);
    $second = ($this->differ)()->diff(($this->bundleOf)([$admin, $amount, $nine, $ten]), $target, $counterpart);

    $expected = [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, '10'),
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, '9'),
        ArtifactKindRegistry::identifierFor(ArtifactKind::FieldDefinitions->value, 'amount'),
        ArtifactKindRegistry::identifierFor(ArtifactKind::Roles->value, 'admin'),
    ];

    expect(($this->identifiersOf)($first))->toBe($expected)
        ->and(($this->identifiersOf)($second))->toBe($expected);
});

it('writes no baseline while comparing an addition a removal a modification and a conflict', function (): void {
    $counterpart = ($this->counterpart)();
    $firstVersion = ($this->payload)('Rechnung', 'amount');
    $secondVersion = ($this->payload)('Beleg', 'total');
    $thirdVersion = ($this->payload)('Quittung', 'net');

    $baseline = [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'removed') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'removed', $firstVersion),
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'modified') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'modified', $firstVersion),
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'conflicted') => ($this->hashOf)(ArtifactKind::ObjectTypes->value, 'conflicted', $thirdVersion),
    ];

    ($this->rememberBaseline)($counterpart, $baseline);

    $source = ($this->bundleOf)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'modified', $secondVersion),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'conflicted', $secondVersion),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'added', $firstVersion),
    ]);

    $target = ($this->bundleOf)([
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'modified', $firstVersion),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'conflicted', $firstVersion),
        ($this->artifact)(ArtifactKind::ObjectTypes->value, 'removed', $firstVersion),
    ]);

    $diff = ($this->differ)()->diff($source, $target, $counterpart);

    expect(($this->entryFor)($diff, ArtifactKind::ObjectTypes->value, 'added')->state)->toBe(DiffState::Added)
        ->and(($this->entryFor)($diff, ArtifactKind::ObjectTypes->value, 'removed')->state)->toBe(DiffState::Removed)
        ->and(($this->entryFor)($diff, ArtifactKind::ObjectTypes->value, 'modified')->state)->toBe(DiffState::Modified)
        ->and(($this->entryFor)($diff, ArtifactKind::ObjectTypes->value, 'conflicted')->state)->toBe(DiffState::Conflicted)
        ->and($this->baselines->hashesFor($counterpart))->toBe($baseline);
});

it('returns an empty comparison for two empty bundles', function (): void {
    $diff = ($this->differ)()->diff(($this->bundleOf)([]), ($this->bundleOf)([]), ($this->counterpart)());

    expect($diff->diffs)->toBe([])
        ->and($diff->conflicts())->toBe([]);
});
