<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\ApplyReport;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Enums\Users\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\ConfigBundle\ArtifactWriteSequence;
use App\Support\ConfigBundle\ConfigBundleApplier;
use App\Support\Promotion\ArtifactDependencyResolver;
use App\Support\Promotion\PromotionBaselineStore;
use App\Support\Tenancy\ActingUserContext;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RecordingArtifactWriter;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actingUser = AccessContext::user($this->tenant);

    $this->kind = ArtifactKind::Skills;

    $this->bundle = new ConfigBundle(
        manifest: new BundleManifest(schemaVersion: 1, sourceLabel: 'Testquelle', artifactCounts: []),
        artifacts: [
            new BundleArtifact($this->kind, 'alpha', ['key' => 'alpha', 'name' => 'Alpha']),
            new BundleArtifact($this->kind, 'beta', ['key' => 'beta', 'name' => 'Beta']),
            new BundleArtifact($this->kind, 'gamma', ['key' => 'gamma', 'name' => 'Gamma']),
        ],
    );

    $this->diff = new BundleDiff(array_map(
        fn (string $key): ArtifactDiff => new ArtifactDiff(
            kind: $this->kind,
            key: $key,
            state: DiffState::Added,
            sourceHash: 'source-'.$key,
            targetHash: null,
            baselineHash: null,
            changedPaths: [],
        ),
        ['alpha', 'beta', 'gamma'],
    ));

    $this->selection = new PromotionSelection(array_map(
        fn (string $key): array => ['kind' => $this->kind, 'key' => $key],
        ['alpha', 'beta', 'gamma'],
    ));

    /** @var callable(RecordingArtifactWriter):ConfigBundleApplier */
    $this->applierWriting = fn (RecordingArtifactWriter $writer): ConfigBundleApplier => new ConfigBundleApplier(
        new ActingUserContext,
        app(ArtifactDependencyResolver::class),
        new ArtifactWriteSequence,
        new ArtifactWriterRegistry([$writer]),
    );

    $this->boundContext = fn (): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [ModelStub::make(Tenant::class, [
                'id' => (string) $this->tenant->getKey(),
                'name' => 'Acme',
                'slug' => 'acme',
            ])->getAttributes()],
            str_contains($sql, 'from "users"') => [ModelStub::make(User::class, [
                'id' => (string) $this->actingUser->getKey(),
                'tenant_id' => (string) $this->tenant->getKey(),
                'name' => 'Rita',
                'email' => 'rita@example.test',
                'status' => UserStatus::Accepted->value,
            ])->getAttributes()],
            default => [],
        },
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    app()->forgetInstance(PromotionBaselineStore::class);
    app()->forgetInstance(AdminArtifactAuditor::class);
});

it('carries an artifact that waits for a later reference through a second pass', function (): void {
    $writer = new RecordingArtifactWriter(
        [$this->kind],
        fn (BundleArtifact $artifact, DiffState $state, int $attempt): ArtifactWriteResult => new ArtifactWriteResult(
            kind: $this->kind,
            key: $artifact->key,
            action: ArtifactWriteAction::Created,
            modelId: $attempt === 1 && $artifact->key === 'alpha' ? null : 'id-'.$artifact->key,
            notes: [],
            awaitedReference: $artifact->key === 'alpha' && $attempt === 1 ? 'wartet auf beta' : null,
        ),
    );

    $connection = ($this->boundContext)();

    $report = ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    );

    $alpha = array_values(array_filter(
        $report->results,
        static fn (ArtifactWriteResult $result): bool => $result->key === 'alpha',
    ));

    expect($writer->appliedKeys())->toBe(['alpha', 'beta', 'gamma', 'alpha'])
        ->and($writer->applied[3]['state'])->toBe(DiffState::Modified)
        ->and($alpha)->toHaveCount(1)
        ->and($alpha[0]->modelId)->toBe('id-alpha')
        ->and($alpha[0]->notes)->toBe([])
        ->and($connection->openedTransactions)->toBe(1);
});

it('leaves an unresolvable reference as a note instead of looping over it forever', function (): void {
    $writer = new RecordingArtifactWriter(
        [$this->kind],
        fn (BundleArtifact $artifact): ArtifactWriteResult => new ArtifactWriteResult(
            kind: $this->kind,
            key: $artifact->key,
            action: ArtifactWriteAction::Created,
            modelId: null,
            notes: [],
            awaitedReference: $artifact->key === 'alpha' ? 'wartet auf nichts' : null,
        ),
    );

    ($this->boundContext)();

    $report = ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    );

    expect($writer->appliedKeys())->toBe(['alpha', 'beta', 'gamma', 'alpha'])
        ->and($report->results)->toHaveCount(3)
        ->and($report->results[0]->notes)->toContain('wartet auf nichts');
});

it('stops the whole bundle at the artifact whose write refuses and never reaches the rest', function (): void {
    $writer = new RecordingArtifactWriter(
        [$this->kind],
        fn (BundleArtifact $artifact): ArtifactWriteResult => $artifact->key === 'beta'
            ? throw new RuntimeException('der Schreibvorgang bricht ab')
            : new ArtifactWriteResult(
                kind: $this->kind,
                key: $artifact->key,
                action: ArtifactWriteAction::Created,
                modelId: 'id-'.$artifact->key,
            ),
    );

    $connection = ($this->boundContext)();

    expect(fn (): ApplyReport => ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    ))->toThrow(RuntimeException::class);

    expect($writer->appliedKeys())->toBe(['alpha', 'beta'])
        ->and($writer->flushes)->toBe(0)
        ->and($connection->openedTransactions)->toBe(1);
});

it('adds what a writer only settles at the end of the bundle to the report', function (): void {
    $flushed = new ArtifactWriteResult(
        kind: $this->kind,
        key: 'rollen-rechte',
        action: ArtifactWriteAction::Updated,
        modelId: 'id-rollen-rechte',
    );

    $writer = new RecordingArtifactWriter([$this->kind], null, [$flushed]);

    ($this->boundContext)();

    $report = ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    );

    expect($writer->flushes)->toBe(1)
        ->and($report->results)->toHaveCount(4)
        ->and($report->results[3])->toBe($flushed);
});

it('writes nothing of its own while it drives the writers of a bundle', function (): void {
    $writer = new RecordingArtifactWriter([$this->kind]);

    $connection = ($this->boundContext)();

    ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    );

    expect($connection->writtenStatements)->toBe([])
        ->and($writer->appliedKeys())->toBe(['alpha', 'beta', 'gamma']);
});

it('remembers no promotion baseline and writes no admin audit entry while it applies a bundle', function (): void {
    $baselines = Mockery::spy(PromotionBaselineStore::class);
    $auditor = Mockery::spy(AdminArtifactAuditor::class);

    app()->instance(PromotionBaselineStore::class, $baselines);
    app()->instance(AdminArtifactAuditor::class, $auditor);

    $writer = new RecordingArtifactWriter([$this->kind]);

    ($this->boundContext)();

    ($this->applierWriting)($writer)->apply(
        $this->actingUser,
        $this->bundle,
        $this->diff,
        $this->selection,
        [],
    );

    $baselines->shouldNotHaveReceived('remember');
    $baselines->shouldNotHaveReceived('rememberOne');
    $auditor->shouldNotHaveReceived('recordEvent');

    expect($writer->appliedKeys())->toBe(['alpha', 'beta', 'gamma']);
});
