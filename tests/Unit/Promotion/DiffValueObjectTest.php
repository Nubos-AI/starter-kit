<?php

declare(strict_types=1);

use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->diff = fn (string $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: 'source-hash',
        targetHash: 'target-hash',
        baselineHash: 'baseline-hash',
        changedPaths: ['label'],
    );

    $this->decision = fn (string $kind, string $key, ConflictResolution $resolution = ConflictResolution::TakeSource): ConflictDecision => new ConflictDecision(
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
        $diff = ($this->diff)(ArtifactKind::ObjectTypes->value, 'invoice', $state);

        expect($diff->isDecidable([]))->toBeTrue($state->name.' must be decidable without a decision');
    }
});

test('a conflicted artifact without a decision is not decidable', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Conflicted);

    expect($diff->isDecidable([]))->toBeFalse();
});

test('a conflicted artifact becomes decidable once its own decision is present', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Conflicted);

    $decisions = [
        ($this->decision)(ArtifactKind::Roles->value, 'admin'),
        ($this->decision)(ArtifactKind::ObjectTypes->value, 'invoice', ConflictResolution::KeepTarget),
    ];

    expect($diff->isDecidable($decisions))->toBeTrue();
});

test('a decision for the same key under another kind decides nothing', function (): void {
    $diff = ($this->diff)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Conflicted);

    expect($diff->isDecidable([($this->decision)(ArtifactKind::Reports->value, 'invoice')]))->toBeFalse();
});

test('an empty selection never blocks on a conflict', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::Roles->value, 'admin', DiffState::Conflicted),
    ]);

    expect($bundleDiff->hasUndecidedConflicts(new PromotionSelection([]), []))->toBeFalse();
});

test('a comparison without a conflict yields an empty conflict list', function (): void {
    $bundleDiff = new BundleDiff([
        ($this->diff)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Unchanged),
    ]);

    expect($bundleDiff->conflicts())->toBe([]);
});

test('adding to a selection returns a new instance carrying the pair and leaves the original untouched', function (): void {
    $base = ($this->selectionOf)([[ArtifactKind::Roles->value, 'admin']]);

    $extended = $base->withAdded(ArtifactKind::ObjectTypes->value, 'invoice');

    expect($extended->contains(ArtifactKind::ObjectTypes->value, 'invoice'))->toBeTrue()
        ->and($extended->contains(ArtifactKind::Roles->value, 'admin'))->toBeTrue()
        ->and($extended->count())->toBe(2)
        ->and($extended)->not->toBe($base)
        ->and($base->contains(ArtifactKind::ObjectTypes->value, 'invoice'))->toBeFalse()
        ->and($base->contains(ArtifactKind::Roles->value, 'admin'))->toBeTrue()
        ->and($base->count())->toBe(1);
});

test('adding a pair the selection already holds creates no duplicate', function (): void {
    $selection = ($this->selectionOf)([[ArtifactKind::Roles->value, 'admin']]);

    $again = $selection->withAdded(ArtifactKind::Roles->value, 'admin');

    expect($again->count())->toBe(1)
        ->and($again->contains(ArtifactKind::Roles->value, 'admin'))->toBeTrue();
});

test('a selection tells the same key apart under two kinds', function (): void {
    $selection = ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'invoice']]);

    expect($selection->contains(ArtifactKind::ObjectTypes->value, 'invoice'))->toBeTrue()
        ->and($selection->contains(ArtifactKind::Reports->value, 'invoice'))->toBeFalse();
});

test('a conflict decision survives the round trip through its array form without loss', function (): void {
    $decision = ($this->decision)(ArtifactKind::Roles->value, 'admin', ConflictResolution::KeepTarget);

    $restored = ConflictDecision::fromArray($decision->toArray());

    expect($restored->kind)->toBe(ArtifactKind::Roles->value)
        ->and($restored->key)->toBe('admin')
        ->and($restored->resolution)->toBe(ConflictResolution::KeepTarget)
        ->and($restored->decidedById)->toBe($decision->decidedById)
        ->and($restored->decidedAt->equalTo($decision->decidedAt))->toBeTrue();
});

test('a decision array missing a required part is refused', function (array $changes, array $removals): void {
    $decision = array_replace(($this->decision)(ArtifactKind::Roles->value, 'admin')->toArray(), $changes);

    foreach ($removals as $removal) {
        unset($decision[$removal]);
    }

    expect(fn (): ConflictDecision => ConflictDecision::fromArray($decision))->toThrow(InvalidArgumentException::class);
})->with([
    'without a decision time instead of being stamped with now' => [[], ['decided_at']],
    'without a deciding user' => [['decided_by_id' => ''], []],
    'without a business key' => [[], ['key']],
]);
