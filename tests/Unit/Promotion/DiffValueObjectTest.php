<?php

declare(strict_types=1);

use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\DependencyResolution;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->diff = fn (ArtifactKind $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: 'source-hash',
        targetHash: 'target-hash',
        baselineHash: 'baseline-hash',
        changedPaths: ['label'],
    );

    $this->decision = fn (ArtifactKind $kind, string $key, ConflictResolution $resolution = ConflictResolution::TakeSource): ConflictDecision => new ConflictDecision(
        kind: $kind,
        key: $key,
        resolution: $resolution,
        decidedById: '01JBQ0Z6Q9K7X3M2N4P5R6S7T8',
        decidedAt: CarbonImmutable::parse('2026-03-04T10:15:30+00:00'),
    );

    $this->selectionOf = fn (array $pairs): PromotionSelection => array_reduce(
        $pairs,
        static fn (PromotionSelection $carry, array $pair): PromotionSelection => $carry->withAdded($pair[0], $pair[1]),
        new PromotionSelection([]),
    );
});

test('an artifact that is not conflicted is decidable without any decision', function (): void {
    $states = [DiffState::Added, DiffState::Removed, DiffState::Modified, DiffState::Unchanged];

    foreach ($states as $state) {
        $diff = ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', $state);

        expect($diff->isDecidable([]))->toBeTrue($state->name.' must be decidable without a decision');
    }
});

test('a conflicted artifact without a decision is not decidable', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Conflicted);

    expect($diff->isDecidable([]))->toBeFalse();
});

test('a conflicted artifact becomes decidable once its own decision is present', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Conflicted);

    $decisions = [
        ($this->decision)(ArtifactKind::Roles, 'admin'),
        ($this->decision)(ArtifactKind::ObjectTypes, 'invoice', ConflictResolution::KeepTarget),
    ];

    expect($diff->isDecidable($decisions))->toBeTrue();
});

test('a decision for the same key under another kind decides nothing', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Conflicted);

    expect($diff->isDecidable([($this->decision)(ArtifactKind::Reports, 'invoice')]))->toBeFalse();
});

test('an undecided conflict inside the selection blocks the promotion', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Modified),
        ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted),
    ]);

    $selection = ($this->selectionOf)([
        [ArtifactKind::ObjectTypes, 'invoice'],
        [ArtifactKind::Roles, 'admin'],
    ]);

    expect($bundleDiff->hasUndecidedConflicts($selection, []))->toBeTrue();
});

test('the decided conflict inside the selection stops blocking the promotion', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted),
    ]);

    $selection = ($this->selectionOf)([[ArtifactKind::Roles, 'admin']]);

    expect($bundleDiff->hasUndecidedConflicts($selection, [($this->decision)(ArtifactKind::Roles, 'admin')]))->toBeFalse();
});

test('an undecided conflict outside the selection leaves the selective promotion open', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Modified),
        ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted),
    ]);

    $selection = ($this->selectionOf)([[ArtifactKind::ObjectTypes, 'invoice']]);

    expect($bundleDiff->hasUndecidedConflicts($selection, []))->toBeFalse();
});

test('an empty selection never blocks on a conflict', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted),
    ]);

    expect($bundleDiff->hasUndecidedConflicts(new PromotionSelection([]), []))->toBeFalse();
});

test('the conflict list holds the conflicted artifacts only', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Modified),
        ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted),
        ($this->diff)(ArtifactKind::Reports, 'pipeline', DiffState::Added),
        ($this->diff)(ArtifactKind::Segments, 'north', DiffState::Conflicted),
    ]);

    $conflictKeys = array_map(
        static fn (ArtifactDiff $diff): string => $diff->key,
        $bundleDiff->conflicts(),
    );

    expect($conflictKeys)->toEqualCanonicalizing(['admin', 'north']);
});

test('a comparison without a conflict yields an empty conflict list', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Unchanged),
    ]);

    expect($bundleDiff->conflicts())->toBe([]);
});

test('adding to a selection returns a new instance carrying the pair', function (): void {
    $base = new PromotionSelection([]);

    $extended = $base->withAdded(ArtifactKind::ObjectTypes, 'invoice');

    expect($extended->contains(ArtifactKind::ObjectTypes, 'invoice'))->toBeTrue()
        ->and($extended->count())->toBe(1)
        ->and($extended)->not->toBe($base);
});

test('adding to a selection leaves the original selection untouched', function (): void {
    $base = ($this->selectionOf)([[ArtifactKind::Roles, 'admin']]);

    $base->withAdded(ArtifactKind::ObjectTypes, 'invoice');

    expect($base->contains(ArtifactKind::ObjectTypes, 'invoice'))->toBeFalse()
        ->and($base->contains(ArtifactKind::Roles, 'admin'))->toBeTrue()
        ->and($base->count())->toBe(1);
});

test('adding a pair the selection already holds creates no duplicate', function (): void {
    $selection = ($this->selectionOf)([[ArtifactKind::Roles, 'admin']]);

    $again = $selection->withAdded(ArtifactKind::Roles, 'admin');

    expect($again->count())->toBe(1)
        ->and($again->contains(ArtifactKind::Roles, 'admin'))->toBeTrue();
});

test('a selection tells the same key apart under two kinds', function (): void {
    $selection = ($this->selectionOf)([[ArtifactKind::ObjectTypes, 'invoice']]);

    expect($selection->contains(ArtifactKind::ObjectTypes, 'invoice'))->toBeTrue()
        ->and($selection->contains(ArtifactKind::Reports, 'invoice'))->toBeFalse();
});

test('a selection refuses a write to its properties', function (): void {
    $selection = ($this->selectionOf)([[ArtifactKind::Roles, 'admin']]);

    expect(fn (): mixed => $selection->pairs = [])->toThrow(Error::class);
});

test('a diff refuses a write to its properties', function (): void {
    $diff = ($this->diff)(ArtifactKind::Roles, 'admin', DiffState::Conflicted);

    expect(fn (): mixed => $diff->state = DiffState::Unchanged)->toThrow(Error::class);
});

test('the two conflict resolutions name the sandbox and the production side in German', function (): void {
    expect(ConflictResolution::TakeSource->label())->toBe('Quelle übernehmen')
        ->and(ConflictResolution::KeepTarget->label())->toBe('Live behalten');
});

test('the resolution enum offers exactly the two choices of the decision', function (): void {
    $names = array_map(
        static fn (ConflictResolution $resolution): string => $resolution->name,
        ConflictResolution::cases(),
    );

    expect($names)->toEqualCanonicalizing(['TakeSource', 'KeepTarget']);
});

test('the diff states cover addition removal modification sameness and conflict', function (): void {
    $names = array_map(
        static fn (DiffState $state): string => $state->name,
        DiffState::cases(),
    );

    expect($names)->toEqualCanonicalizing(['Added', 'Removed', 'Modified', 'Unchanged', 'Conflicted']);
});

test('a conflict decision survives the round trip through its array form without loss', function (): void {
    $decision = ($this->decision)(ArtifactKind::Roles, 'admin', ConflictResolution::KeepTarget);

    $restored = ConflictDecision::fromArray($decision->toArray());

    expect($restored->kind)->toBe(ArtifactKind::Roles)
        ->and($restored->key)->toBe('admin')
        ->and($restored->resolution)->toBe(ConflictResolution::KeepTarget)
        ->and($restored->decidedById)->toBe($decision->decidedById)
        ->and($restored->decidedAt->equalTo($decision->decidedAt))->toBeTrue();
});

test('a decision array without a decision time is refused instead of stamped with now', function (): void {
    $decision = ($this->decision)(ArtifactKind::Roles, 'admin')->toArray();
    unset($decision['decided_at']);

    expect(fn (): ConflictDecision => ConflictDecision::fromArray($decision))->toThrow(InvalidArgumentException::class);
});

test('a decision array without a deciding user is refused', function (): void {
    $decision = ($this->decision)(ArtifactKind::Roles, 'admin')->toArray();
    $decision['decided_by_id'] = '';

    expect(fn (): ConflictDecision => ConflictDecision::fromArray($decision))->toThrow(InvalidArgumentException::class);
});

test('a decision array without a business key is refused', function (): void {
    $decision = ($this->decision)(ArtifactKind::Roles, 'admin')->toArray();
    unset($decision['key']);

    expect(fn (): ConflictDecision => ConflictDecision::fromArray($decision))->toThrow(InvalidArgumentException::class);
});

test('a refused dependency names its kind its key and a German reason', function (): void {
    $resolution = new DependencyResolution(
        selection: ($this->selectionOf)([[ArtifactKind::ObjectTypes, 'invoice']]),
        pulledIn: [['kind' => ArtifactKind::FieldGroups, 'key' => 'billing', 'optional' => false]],
        refusals: [['kind' => ArtifactKind::Reports, 'key' => 'pipeline', 'reason' => 'Die Abhängigkeit fehlt in der Sandbox.']],
    );

    expect($resolution->selection->contains(ArtifactKind::ObjectTypes, 'invoice'))->toBeTrue()
        ->and($resolution->pulledIn[0]['kind'])->toBe(ArtifactKind::FieldGroups)
        ->and($resolution->pulledIn[0]['key'])->toBe('billing')
        ->and($resolution->pulledIn[0]['optional'])->toBeFalse()
        ->and(array_keys($resolution->refusals[0]))->toEqualCanonicalizing(['kind', 'key', 'reason'])
        ->and($resolution->refusals[0]['kind'])->toBe(ArtifactKind::Reports)
        ->and($resolution->refusals[0]['key'])->toBe('pipeline')
        ->and($resolution->refusals[0]['reason'])->toBe('Die Abhängigkeit fehlt in der Sandbox.');
});
