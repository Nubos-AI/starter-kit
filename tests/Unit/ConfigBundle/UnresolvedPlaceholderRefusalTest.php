<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\ApplyReport;
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
use App\Enums\Webhooks\WebhookEventType;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedPlaceholderException;
use App\Support\ConfigBundle\ConfigBundleApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actingUser = AccessContext::user($this->tenant);

    $this->marker = (string) config('engine.config_bundle.placeholder');

    $this->applier = fn (): ConfigBundleApplier => app(ConfigBundleApplier::class);

    $this->bundleOf = static fn (array $artifacts): ConfigBundle => new ConfigBundle(
        manifest: new BundleManifest(
            schemaVersion: 1,
            sourceLabel: 'Testquelle',
            artifactCounts: [],
        ),
        artifacts: array_values($artifacts),
    );

    $this->diffEntry = static fn (ArtifactKind $kind, string $key, DiffState $state): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: $state,
        sourceHash: in_array($state, [DiffState::Unchanged, DiffState::Removed], true) ? null : 'source-'.$kind->identifierFor($key),
        targetHash: $state === DiffState::Added ? null : 'target-'.$kind->identifierFor($key),
        baselineHash: $state === DiffState::Conflicted ? 'baseline-'.$kind->identifierFor($key) : null,
        changedPaths: $state === DiffState::Conflicted ? ['payload'] : [],
    );

    $this->pair = static fn (ArtifactKind $kind, string $key): array => ['kind' => $kind, 'key' => $key];

    $this->subscriptionArtifact = fn (array $overrides = []): BundleArtifact => new BundleArtifact(
        ArtifactKind::WebhookSubscriptions,
        'Deal-Sync',
        [
            'key' => 'Deal-Sync',
            'object_type_id' => 'deal',
            'role_id' => 'tenant:Integration',
            'target_url' => 'https://hooks.ziel.test/inbound',
            'event_types' => [WebhookEventType::RecordCreated->value],
            ...$overrides,
        ],
    );

    $this->invoiceArtifact = static fn (): BundleArtifact => new BundleArtifact(ArtifactKind::ObjectTypes, 'invoice', [
        'key' => 'invoice',
        'hierarchy_relationship_type_id' => null,
        'slug' => 'invoice',
        'business_key_prefix' => 'INV',
        'is_system' => false,
        'is_navigable' => true,
        'requires_deletion_reason' => false,
        'storage_strategy' => 'generic',
        'name' => 'Invoice',
        'nav_icon' => 'database',
        'nav_position' => 7,
        'record_number_format' => 'R#####',
        'retention_days' => 365,
        'dedup_keys' => ['name'],
    ]);

    $this->settledPrerequisites = fn (): array => [
        ($this->diffEntry)(ArtifactKind::ObjectTypes, 'deal', DiffState::Unchanged),
        ($this->diffEntry)(ArtifactKind::Roles, 'tenant:Integration', DiffState::Unchanged),
    ];

    /** @var callable(list<BundleArtifact>, list<ArtifactDiff>, list<array{kind: ArtifactKind, key: string}>, list<ConflictDecision>):(callable():ApplyReport) */
    $this->run = fn (array $artifacts, array $diffs, array $pairs, array $decisions = []): callable => fn (): ApplyReport => ($this->applier)()->apply(
        $this->actingUser,
        ($this->bundleOf)($artifacts),
        new BundleDiff($diffs),
        new PromotionSelection($pairs),
        $decisions,
    );

    /** @var callable(callable():mixed):?Throwable */
    $this->capture = static function (callable $operation): ?Throwable {
        try {
            $operation();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads a non empty placeholder marker from the configuration', function (): void {
    expect($this->marker)->toBeString()
        ->and($this->marker)->not->toBe('');
});

it('refuses the whole run before it reaches the database when a selected webhook subscription still carries the marker', function (): void {
    $run = ($this->run)(
        [($this->invoiceArtifact)(), ($this->subscriptionArtifact)(['target_url' => $this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Added),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Added),
        ],
        [
            ($this->pair)(ArtifactKind::ObjectTypes, 'invoice'),
            ($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync'),
        ],
    );

    $thrown = ($this->capture)($run);

    expect($thrown)->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and($thrown->affected)->toBe([
            ['kind' => ArtifactKind::WebhookSubscriptions->value, 'key' => 'Deal-Sync'],
        ])
        ->and($thrown)->not->toBeInstanceOf(PDOException::class);
});

it('refuses a target that only embeds the marker inside a longer address', function (): void {
    $run = ($this->run)(
        [($this->subscriptionArtifact)(['target_url' => 'https://hooks.ziel.test/'.$this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Added),
        ],
        [($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync')],
    );

    $thrown = ($this->capture)($run);

    expect($thrown)->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and($thrown)->not->toBeInstanceOf(PDOException::class);
});

it('does not let an unselected artifact with the marker block the selected clean one', function (): void {
    $run = ($this->run)(
        [($this->invoiceArtifact)(), ($this->subscriptionArtifact)(['target_url' => $this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Added),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Added),
        ],
        [($this->pair)(ArtifactKind::ObjectTypes, 'invoice')],
    );

    expect(($this->capture)($run))->not->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
});

it('does not let a selected pair that would not be written block the run', function (DiffState $state, bool $keepsTarget): void {
    $decisions = $keepsTarget
        ? [new ConflictDecision(
            kind: ArtifactKind::WebhookSubscriptions,
            key: 'Deal-Sync',
            resolution: ConflictResolution::KeepTarget,
            decidedById: (string) $this->actingUser->getKey(),
            decidedAt: CarbonImmutable::now(),
        )]
        : [];

    $run = ($this->run)(
        [($this->invoiceArtifact)(), ($this->subscriptionArtifact)(['target_url' => $this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Added),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', $state),
        ],
        [
            ($this->pair)(ArtifactKind::ObjectTypes, 'invoice'),
            ($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync'),
        ],
        $decisions,
    );

    expect(($this->capture)($run))->not->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
})->with([
    'unchanged subscription' => [DiffState::Unchanged, false],
    'removed subscription' => [DiffState::Removed, false],
    'conflict decided as keep target' => [DiffState::Conflicted, true],
]);

it('does not treat every string as a carrier when the configured marker is empty', function (): void {
    config()->set('engine.config_bundle.placeholder', '');

    $run = ($this->run)(
        [($this->invoiceArtifact)()],
        [($this->diffEntry)(ArtifactKind::ObjectTypes, 'invoice', DiffState::Added)],
        [($this->pair)(ArtifactKind::ObjectTypes, 'invoice')],
    );

    expect(config('engine.config_bundle.placeholder'))->toBe('')
        ->and(($this->capture)($run))->not->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
});

it('lets a selected webhook subscription whose target is resolved through to the write', function (): void {
    $run = ($this->run)(
        [($this->subscriptionArtifact)()],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Added),
        ],
        [($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync')],
    );

    expect(($this->capture)($run))->not->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
});

it('names the single affected artifact in a german singular message', function (): void {
    $thrown = ($this->capture)(($this->run)(
        [($this->subscriptionArtifact)(['target_url' => $this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Added),
        ],
        [($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync')],
    ));

    expect($thrown)->toBeInstanceOf(UnresolvedPlaceholderException::class);

    $message = $thrown->getMessage();

    expect($message)->toContain(ArtifactKind::WebhookSubscriptions->identifierFor('Deal-Sync'))
        ->and($message)->toContain('Platzhalter')
        ->and($message)->toContain('Artefakt')
        ->and($message)->not->toContain('Artefakte')
        ->and($message)->not->toContain($this->marker);
});

it('checks the placeholder before the conflict check', function (): void {
    $run = ($this->run)(
        [($this->subscriptionArtifact)(['target_url' => $this->marker])],
        [
            ...($this->settledPrerequisites)(),
            ($this->diffEntry)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync', DiffState::Conflicted),
        ],
        [($this->pair)(ArtifactKind::WebhookSubscriptions, 'Deal-Sync')],
    );

    $thrown = ($this->capture)($run);

    expect($thrown)->toBeInstanceOf(UnresolvedPlaceholderException::class)
        ->and($thrown)->not->toBeInstanceOf(UndecidedConflictsException::class)
        ->and($thrown)->not->toBeInstanceOf(PDOException::class);
});

it('keeps the marker out of app as a literal and declares it exactly once in the engine configuration', function (): void {
    expect($this->marker)->not->toBe('');

    $offending = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (str_contains($file->getContents(), $this->marker)) {
            $offending[] = $file->getRelativePathname();
        }
    }

    expect(File::allFiles(app_path()))->not->toBe([])
        ->and($offending)->toBe([])
        ->and(substr_count(File::get(config_path('engine.php')), $this->marker))->toBe(1);
});
