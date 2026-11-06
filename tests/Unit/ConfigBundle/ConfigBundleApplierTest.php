<?php

declare(strict_types=1);

use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ApplyReport;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\ConfigBundle\ConfigBundleApplier;
use App\Support\Promotion\ArtifactDependencyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\AccessContext;
use Tests\Support\InstalledArtifactKinds;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actingUser = AccessContext::user($this->tenant);

    $this->applier = fn (): ConfigBundleApplier => app(ConfigBundleApplier::class);

    $this->bundleOf = static fn (array $artifacts): ConfigBundle => new ConfigBundle(
        manifest: new BundleManifest(
            schemaVersion: 1,
            sourceLabel: 'Testquelle',
            artifactCounts: [],
        ),
        artifacts: array_values($artifacts),
    );

    $this->diffOf = static fn (array $diffs): BundleDiff => new BundleDiff(array_values($diffs));

    $this->selectionOf = static fn (array $pairs): PromotionSelection => new PromotionSelection(array_values($pairs));

    $this->pair = static fn (ArtifactKind $kind, string $key): array => ['kind' => $kind, 'key' => $key];

    $this->addedDiff = static fn (ArtifactKind $kind, string $key): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: DiffState::Added,
        sourceHash: 'source-'.$kind->identifierFor($key),
        targetHash: null,
        baselineHash: null,
        changedPaths: [],
    );

    $this->unchangedDiff = static fn (ArtifactKind $kind, string $key): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: DiffState::Unchanged,
        sourceHash: null,
        targetHash: 'target-'.$kind->identifierFor($key),
        baselineHash: null,
        changedPaths: [],
    );

    $this->conflictedDiff = static fn (ArtifactKind $kind, string $key): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: DiffState::Conflicted,
        sourceHash: 'source-'.$kind->identifierFor($key),
        targetHash: 'target-'.$kind->identifierFor($key),
        baselineHash: 'baseline-'.$kind->identifierFor($key),
        changedPaths: ['payload'],
    );

    $this->removedDiff = static fn (ArtifactKind $kind, string $key): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: DiffState::Removed,
        sourceHash: null,
        targetHash: 'target-'.$kind->identifierFor($key),
        baselineHash: 'target-'.$kind->identifierFor($key),
        changedPaths: [],
    );

    $this->decisionOf = fn (ArtifactKind $kind, string $key, ConflictResolution $resolution): ConflictDecision => new ConflictDecision(
        kind: $kind,
        key: $key,
        resolution: $resolution,
        decidedById: (string) $this->actingUser->getKey(),
        decidedAt: CarbonImmutable::now(),
    );

    $this->objectTypePayload = static fn (string $key, string $prefix, array $overrides = []): array => [
        'key' => $key,
        'hierarchy_relationship_type_id' => null,
        'slug' => Str::slug($key),
        'business_key_prefix' => $prefix,
        'is_system' => false,
        'is_navigable' => true,
        'requires_deletion_reason' => false,
        'storage_strategy' => 'generic',
        'name' => Str::headline($key),
        'nav_icon' => 'database',
        'nav_position' => 7,
        'record_number_format' => 'R#####',
        'retention_days' => 365,
        'dedup_keys' => ['name'],
        ...$overrides,
    ];

    $this->fieldDefinitionPayload = static fn (string $key, array $overrides = []): array => [
        'key' => $key,
        'field_group_id' => null,
        'field_type' => 'text_short',
        'is_required' => false,
        'is_unique' => false,
        'is_searchable' => false,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_sortable' => false,
        'is_filterable' => false,
        'is_default_column' => false,
        'is_card_field' => false,
        'list_position' => null,
        'merge_strategy' => null,
        'config' => null,
        'validation_rules' => null,
        'default_value' => null,
        'i18n_labels' => null,
        'i18n_descriptions' => null,
        ...$overrides,
    ];

    /** @var callable(ConfigBundle, BundleDiff, PromotionSelection, list<ConflictDecision>):(callable():ApplyReport) */
    $this->run = fn (ConfigBundle $source, BundleDiff $diff, PromotionSelection $selection, array $decisions = []): callable => fn (): ApplyReport => ($this->applier)()
        ->apply($this->actingUser, $source, $diff, $selection, $decisions);

    /** @var callable(ConfigBundle, BundleDiff, PromotionSelection):array<string, mixed> */
    $this->planOf = fn (ConfigBundle $source, BundleDiff $diff, PromotionSelection $selection): array => (new ReflectionMethod(
        ConfigBundleApplier::class,
        'planOf',
    ))->invoke(($this->applier)(), $source, $diff, $selection);

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

it('blocks the whole run before the database when the selection carries an undecided conflict', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'gamma', ($this->objectTypePayload)('gamma', 'GAM')),
    ]);

    $thrown = ($this->capture)(($this->run)(
        $source,
        ($this->diffOf)([($this->conflictedDiff)(ArtifactKind::ObjectTypes, 'gamma')]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::ObjectTypes, 'gamma')]),
    ));

    expect($thrown)->toBeInstanceOf(UndecidedConflictsException::class)
        ->and($thrown)->not->toBeInstanceOf(PDOException::class)
        ->and(mb_strlen($thrown->getMessage()))->toBeGreaterThan(10)
        ->and(json_encode($thrown->affected))->toContain('gamma');
});

it('does not block the run for an undecided conflict that the selection leaves out', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'gamma', ($this->objectTypePayload)('gamma', 'GAM')),
        new BundleArtifact(ArtifactKind::ObjectTypes, 'invoice', ($this->objectTypePayload)('invoice', 'INV')),
    ]);

    $run = ($this->run)(
        $source,
        ($this->diffOf)([
            ($this->conflictedDiff)(ArtifactKind::ObjectTypes, 'gamma'),
            ($this->addedDiff)(ArtifactKind::ObjectTypes, 'invoice'),
        ]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::ObjectTypes, 'invoice')]),
    );

    expect(($this->capture)($run))->not->toBeInstanceOf(UndecidedConflictsException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
});

it('lets a decided conflict through whichever way it was decided', function (ConflictResolution $resolution): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'alpha', ($this->objectTypePayload)('alpha', 'ALP')),
    ]);

    $run = ($this->run)(
        $source,
        ($this->diffOf)([($this->conflictedDiff)(ArtifactKind::ObjectTypes, 'alpha')]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::ObjectTypes, 'alpha')]),
        [($this->decisionOf)(ArtifactKind::ObjectTypes, 'alpha', $resolution)],
    );

    expect(($this->capture)($run))->not->toBeInstanceOf(UndecidedConflictsException::class)
        ->and(WriteAttempt::reachedTheDatabase($run))->toBeTrue();
})->with([
    'keep target' => [ConflictResolution::KeepTarget],
    'take source' => [ConflictResolution::TakeSource],
]);

it('drops a conflict decided as keep target from the plan and keeps the one decided as take source', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'alpha', ($this->objectTypePayload)('alpha', 'ALP')),
        new BundleArtifact(ArtifactKind::ObjectTypes, 'beta', ($this->objectTypePayload)('beta', 'BET')),
    ]);

    $diff = ($this->diffOf)([
        ($this->conflictedDiff)(ArtifactKind::ObjectTypes, 'alpha'),
        ($this->conflictedDiff)(ArtifactKind::ObjectTypes, 'beta'),
    ]);

    $selection = ($this->selectionOf)([
        ($this->pair)(ArtifactKind::ObjectTypes, 'alpha'),
        ($this->pair)(ArtifactKind::ObjectTypes, 'beta'),
    ]);

    $decisions = [
        ($this->decisionOf)(ArtifactKind::ObjectTypes, 'alpha', ConflictResolution::KeepTarget),
        ($this->decisionOf)(ArtifactKind::ObjectTypes, 'beta', ConflictResolution::TakeSource),
    ];

    $decided = (new ReflectionMethod(ConfigBundleApplier::class, 'withoutKeptTargets'))
        ->invoke(($this->applier)(), $selection, $diff, $decisions);

    $plan = ($this->planOf)($source, $diff, $decided);

    expect(array_map(static fn (array $write): string => $write['artifact']->key, $plan['writes']))->toBe(['beta'])
        ->and($plan['writes'][0]['state'])->toBe(DiffState::Modified)
        ->and($plan['removals'])->toBe([])
        ->and($plan['skipped'])->toBe([]);
});

it('plans a removal of a field before the removal of its object type and a write the other way round', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'contract', ($this->objectTypePayload)('contract', 'CON')),
        new BundleArtifact(ArtifactKind::FieldDefinitions, 'contract:notes', ($this->fieldDefinitionPayload)('contract:notes')),
    ]);

    $removalPlan = ($this->planOf)(
        $source,
        ($this->diffOf)([
            ($this->removedDiff)(ArtifactKind::FieldDefinitions, 'contract:notes'),
            ($this->removedDiff)(ArtifactKind::ObjectTypes, 'contract'),
        ]),
        ($this->selectionOf)([
            ($this->pair)(ArtifactKind::FieldDefinitions, 'contract:notes'),
            ($this->pair)(ArtifactKind::ObjectTypes, 'contract'),
        ]),
    );

    $writePlan = ($this->planOf)(
        $source,
        ($this->diffOf)([
            ($this->addedDiff)(ArtifactKind::FieldDefinitions, 'contract:notes'),
            ($this->addedDiff)(ArtifactKind::ObjectTypes, 'contract'),
        ]),
        ($this->selectionOf)([
            ($this->pair)(ArtifactKind::FieldDefinitions, 'contract:notes'),
            ($this->pair)(ArtifactKind::ObjectTypes, 'contract'),
        ]),
    );

    expect(array_map(static fn (array $removal): string => $removal['kind']->value, $removalPlan['removals']))
        ->toBe([ArtifactKind::FieldDefinitions->value, ArtifactKind::ObjectTypes->value])
        ->and($removalPlan['writes'])->toBe([])
        ->and(array_map(static fn (array $write): string => $write['kind']->value, $writePlan['writes']))
        ->toBe([ArtifactKind::ObjectTypes->value, ArtifactKind::FieldDefinitions->value]);
});

it('plans a skipped result instead of silently dropping a selected artifact the bundle does not carry', function (): void {
    $plan = ($this->planOf)(
        ($this->bundleOf)([]),
        ($this->diffOf)([]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::ObjectTypes, 'phantom')]),
    );

    /** @var list<ArtifactWriteResult> $skipped */
    $skipped = $plan['skipped'];

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($skipped[0]->key)->toBe('phantom')
        ->and($skipped[0]->notes)->not->toBe([])
        ->and($plan['writes'])->toBe([])
        ->and($plan['removals'])->toBe([]);
});

it('leaves an unchanged pair out of the plan entirely', function (): void {
    $plan = ($this->planOf)(
        ($this->bundleOf)([
            new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ($this->objectTypePayload)('deal', 'DEA')),
        ]),
        ($this->diffOf)([($this->unchangedDiff)(ArtifactKind::ObjectTypes, 'deal')]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::ObjectTypes, 'deal')]),
    );

    expect($plan['writes'])->toBe([])
        ->and($plan['removals'])->toBe([])
        ->and($plan['skipped'])->toBe([]);
});

it('blocks the run before the database when the dependency resolver refuses an artifact', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(ArtifactKind::FieldDefinitions, 'ghost:amount', ($this->fieldDefinitionPayload)('ghost:amount')),
    ]);

    $thrown = ($this->capture)(($this->run)(
        $source,
        ($this->diffOf)([($this->addedDiff)(ArtifactKind::FieldDefinitions, 'ghost:amount')]),
        ($this->selectionOf)([($this->pair)(ArtifactKind::FieldDefinitions, 'ghost:amount')]),
    ));

    expect($thrown)->toBeInstanceOf(UnresolvedDependenciesException::class)
        ->and($thrown)->not->toBeInstanceOf(PDOException::class)
        ->and($thrown->refusals)->not->toBe([])
        ->and(json_encode($thrown->refusals))->toContain('ghost');
});

it('marks a dependency the selection leaves out as optional instead of refusing the run', function (): void {
    $source = ($this->bundleOf)([
        new BundleArtifact(
            ArtifactKind::FieldDefinitions,
            'deal:amount',
            ($this->fieldDefinitionPayload)('deal:amount', ['field_group_id' => 'deal:finance']),
        ),
    ]);

    $diff = ($this->diffOf)([
        ($this->unchangedDiff)(ArtifactKind::ObjectTypes, 'deal'),
        ($this->addedDiff)(ArtifactKind::FieldDefinitions, 'deal:amount'),
    ]);

    $resolution = (new ArtifactDependencyResolver)->resolve(
        ($this->selectionOf)([($this->pair)(ArtifactKind::FieldDefinitions, 'deal:amount')]),
        $source,
        $diff,
    );

    expect($resolution->refusals)->toBe([])
        ->and($resolution->pulledIn)->toHaveCount(1)
        ->and($resolution->pulledIn[0]['optional'])->toBeTrue()
        ->and($resolution->pulledIn[0]['kind'])->toBe(ArtifactKind::FieldGroups)
        ->and($resolution->pulledIn[0]['key'])->toBe('deal:finance');
});

it('resolves a writer for every artifact kind from the container-wired registry', function (): void {
    $registry = app(ArtifactWriterRegistry::class);

    foreach (InstalledArtifactKinds::all() as $kind) {
        expect($registry->for($kind))->toBeInstanceOf(ArtifactWriterInterface::class);
    }
});
