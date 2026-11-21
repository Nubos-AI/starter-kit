<?php

declare(strict_types=1);

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\DTOs\ConfigBundle\ApplyReport;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Support\ConfigBundle\ArtifactKindRegistry;
use App\Support\Promotion\PromotionBaselineStore;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\InMemoryPromotionBaselineStore;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->baselines = new InMemoryPromotionBaselineStore;
    app()->instance(PromotionBaselineStore::class, $this->baselines);

    $this->counterpart = 'tenant:'.ModelStub::ulid('source-tenant');
    $this->otherCounterpart = 'bundle:'.ModelStub::ulid('bundle');

    $this->diffOf = static fn (string $kind, string $key, DiffState $state, ?string $sourceHash, ?string $targetHash): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: $sourceHash,
        targetHash: $targetHash,
        baselineHash: null,
        changedPaths: [],
    );

    $this->selectionOf = static fn (array $pairs): PromotionSelection => new PromotionSelection(array_map(
        static fn (array $pair): array => ['kind' => $pair[0], 'key' => $pair[1]],
        $pairs,
    ));

    $this->decisionOf = static fn (string $kind, string $key, ConflictResolution $resolution): ConflictDecision => new ConflictDecision(
        kind: $kind,
        key: $key,
        resolution: $resolution,
        decidedById: ModelStub::ulid('decider'),
        decidedAt: CarbonImmutable::now(),
    );

    $this->reportOf = static fn (array $results = [], array $pulledIn = []): ApplyReport => new ApplyReport($results, $pulledIn, []);

    $this->skippedResult = static fn (string $kind, string $key): ArtifactWriteResult => new ArtifactWriteResult(
        kind: $kind,
        key: $key,
        action: ArtifactWriteAction::Skipped,
        modelId: null,
    );

    $this->advance = static function (BundleDiff $diff, PromotionSelection $selection, array $decisions, ApplyReport $report, string $counterpartKey): void {
        $action = app(ExecutePromotionRunAction::class);
        $step = new ReflectionMethod($action, 'advanceBaselines');

        $step->invoke($action, $counterpartKey, $diff, $selection, $decisions, $report);
    };

    $this->sourceHash = hash('sha256', 'source');
    $this->targetHash = hash('sha256', 'target');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('advances a decided conflict to the hash its resolution keeps', function (ConflictResolution $resolution, string $expectedHashSeed): void {
    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'contact', DiffState::Conflicted, $this->sourceHash, $this->targetHash),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'contact']]),
        [($this->decisionOf)(ArtifactKind::ObjectTypes->value, 'contact', $resolution)],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart))
        ->toBe([ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'contact') => hash('sha256', $expectedHashSeed)]);
})->with([
    'take source advances to the source hash' => [ConflictResolution::TakeSource, 'source'],
    'keep target advances to the target hash' => [ConflictResolution::KeepTarget, 'target'],
]);

it('advances every selected artifact to the hash its diff state carries over', function (array $artifacts, string $expectedHashSeed): void {
    $diff = new BundleDiff(array_map(
        fn (array $artifact): ArtifactDiff => ($this->diffOf)(
            ArtifactKind::ObjectTypes->value,
            $artifact[0],
            $artifact[1],
            $artifact[2] === null ? null : hash('sha256', $artifact[2]),
            $artifact[3] === null ? null : hash('sha256', $artifact[3]),
        ),
        $artifacts,
    ));

    $keys = array_column($artifacts, 0);

    ($this->advance)(
        $diff,
        ($this->selectionOf)(array_map(static fn (string $key): array => [ArtifactKind::ObjectTypes->value, $key], $keys)),
        [],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart))->toBe(collect($keys)
        ->mapWithKeys(static fn (string $key): array => [ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, $key) => hash('sha256', $expectedHashSeed)])
        ->all());
})->with([
    'a taken over added and modified artifact advances to the source hash' => [
        [['deal', DiffState::Added, 'source', null], ['invoice', DiffState::Modified, 'source', 'target']],
        'source',
    ],
    'a selected unchanged artifact advances to the shared hash' => [
        [['deal', DiffState::Unchanged, 'shared', 'shared']],
        'shared',
    ],
]);

it('removes the baseline entry of a taken over removal', function (): void {
    $identifier = ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'legacy');
    $this->baselines->rememberOne($this->counterpart, $identifier, $this->targetHash);

    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'legacy', DiffState::Removed, null, $this->targetHash),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'legacy']]),
        [],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart))->toBe([]);
});

it('leaves the baseline of an unselected modified artifact untouched', function (): void {
    $identifier = ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'contact');
    $this->baselines->rememberOne($this->counterpart, $identifier, $this->targetHash);

    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'contact', DiffState::Modified, $this->sourceHash, $this->targetHash),
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Added, $this->sourceHash, null),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'invoice']]),
        [],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart)[$identifier] ?? null)->toBe($this->targetHash);
});

it('advances an artifact pulled in as a required dependency but not an optional one', function (): void {
    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::FieldDefinitions->value, 'invoice:total', DiffState::Added, $this->sourceHash, null),
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Added, $this->sourceHash, null),
        ($this->diffOf)(ArtifactKind::Segments->value, 'hot-leads', DiffState::Added, $this->sourceHash, null),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::FieldDefinitions->value, 'invoice:total']]),
        [],
        ($this->reportOf)([], [
            ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'invoice', 'optional' => false],
            ['kind' => ArtifactKind::Segments->value, 'key' => 'hot-leads', 'optional' => true],
        ]),
        $this->counterpart,
    );

    $baseline = $this->baselines->hashesFor($this->counterpart);

    expect($baseline)->toHaveKey(ArtifactKindRegistry::identifierFor(ArtifactKind::FieldDefinitions->value, 'invoice:total'))
        ->and($baseline)->toHaveKey(ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'invoice'))
        ->and($baseline)->not->toHaveKey(ArtifactKindRegistry::identifierFor(ArtifactKind::Segments->value, 'hot-leads'));
});

it('leaves a skipped artifact out of the baseline', function (): void {
    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'deal', DiffState::Added, $this->sourceHash, null),
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'invoice', DiffState::Added, $this->sourceHash, null),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'deal'], [ArtifactKind::ObjectTypes->value, 'invoice']]),
        [],
        ($this->reportOf)([($this->skippedResult)(ArtifactKind::ObjectTypes->value, 'invoice')]),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart))
        ->toBe([ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'deal') => $this->sourceHash]);
});

it('keeps the baseline of another counterpart completely untouched', function (): void {
    $foreign = [
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'deal') => hash('sha256', 'other-counterpart-deal'),
        ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'contact') => hash('sha256', 'other-counterpart-contact'),
    ];

    $this->baselines->remember($this->otherCounterpart, $foreign);

    $diff = new BundleDiff([
        ($this->diffOf)(ArtifactKind::ObjectTypes->value, 'deal', DiffState::Modified, $this->sourceHash, $this->targetHash),
    ]);

    ($this->advance)(
        $diff,
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'deal']]),
        [],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->otherCounterpart))->toBe($foreign)
        ->and($this->baselines->hashesFor($this->counterpart))
        ->toBe([ArtifactKindRegistry::identifierFor(ArtifactKind::ObjectTypes->value, 'deal') => $this->sourceHash]);
});

it('leaves an artifact the diff does not carry out of the baseline', function (): void {
    ($this->advance)(
        new BundleDiff([]),
        ($this->selectionOf)([[ArtifactKind::ObjectTypes->value, 'ghost']]),
        [],
        ($this->reportOf)(),
        $this->counterpart,
    );

    expect($this->baselines->hashesFor($this->counterpart))->toBe([]);
});
