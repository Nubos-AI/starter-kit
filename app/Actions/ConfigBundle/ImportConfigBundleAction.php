<?php

declare(strict_types=1);

namespace App\Actions\ConfigBundle;

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\Enums\Promotion\DiffState;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ConfigBundleReader;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Promotion\BundleDiffer;
use App\Support\Promotion\PromotionSourceResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportConfigBundleAction
{
    private string $labelPattern = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private int $counterpartLabelLength = 240;

    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly ConfigBundleReader $reader,
        private readonly ConfigBundleSerializer $serializer,
        private readonly BundleDiffer $differ,
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly ExecutePromotionRunAction $executor,
        private readonly AdminArtifactAuditor $auditor,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws MalformedBundleException
     * @throws UnsupportedBundleSchemaVersionException
     * @throws UndecidedConflictsException
     * @throws TenantUnderMaintenanceException
     * @throws Throwable
     */
    public function execute(User $actor, string $directory, string $label): PromotionRun
    {
        Validator::make(
            ['label' => $label, 'path' => $directory],
            [
                'label' => ['required', 'string', "regex:{$this->labelPattern}"],
                'path' => ['required', 'string'],
            ],
            [],
            ['label' => '--label', 'path' => '--path'],
        )->validate();

        if (!File::isDirectory($directory)) {
            throw ValidationException::withMessages([
                'path' => __('i18n.backend.actions.config_bundle.import_config_bundle_action.the_source_directory_specified_by_path_does_not_exist'),
            ]);
        }

        /** @var PromotionRun|null $imported */
        $imported = null;

        $this->actingUserContext->run(
            (string) $actor->tenant_id,
            (string) $actor->getKey(),
            function (User $boundUser) use ($directory, $label, &$imported): void {
                $imported = $this->import($boundUser, $directory, $label);
            },
        );

        /** @var PromotionRun $imported */
        return $imported;
    }

    /**
     * @throws AuthorizationException
     * @throws UndecidedConflictsException
     * @throws TenantUnderMaintenanceException
     * @throws Throwable
     */
    private function import(User $boundUser, string $directory, string $label): PromotionRun
    {
        if (!$boundUser->hasPermission('config.import') || !$boundUser->isEscalatedAuthority()) {
            throw new AuthorizationException(__('i18n.backend.actions.config_bundle.import_config_bundle_action.only_an_elevated_authority_with_configuration_import_permission_may'));
        }

        $tenantId = (string) $boundUser->tenant_id;

        $this->maintenanceLocks->assertWritable($tenantId, 'config_import', [
            'acting_user_id' => (string) $boundUser->getKey(),
        ]);

        $source = $this->reader->readFrom($directory);
        $sourceLabel = Str::limit($source->manifest->sourceLabel, $this->counterpartLabelLength, '');
        $counterpartKey = "bundle:{$sourceLabel}";

        $diff = $this->differ->diff(
            $source,
            $this->serializer->serialize($tenantId),
            $counterpartKey,
        );

        $conflicts = $diff->conflicts();

        if ($conflicts !== []) {
            throw UndecidedConflictsException::forUndecided($conflicts);
        }

        $executed = $this->executor->execute(
            $this->storeRun($boundUser, $source, $diff, $counterpartKey),
        );

        $this->auditor->recordEvent($executed, 'operation.config_imported', [
            'label' => $label,
            'sourceLabel' => $source->manifest->sourceLabel,
            'counterpartKey' => $counterpartKey,
            'promotionRunId' => (string) $executed->getKey(),
            'snapshotGroupId' => $executed->snapshot_group_id,
            'writtenCount' => $executed->report['written_count'] ?? null,
        ], $tenantId);

        return $executed;
    }

    /**
     * @throws Throwable
     */
    private function storeRun(User $boundUser, ConfigBundle $source, BundleDiff $diff, string $counterpartKey): PromotionRun
    {
        $changed = array_values(array_filter(
            $diff->diffs,
            static fn (ArtifactDiff $entry): bool => $entry->state !== DiffState::Unchanged,
        ));

        return DB::transaction(function () use ($boundUser, $source, $changed, $counterpartKey): PromotionRun {
            $run = PromotionRun::query()->create([
                'tenant_id' => (string) $boundUser->tenant_id,
                'source_tenant_id' => null,
                'triggered_by_id' => (string) $boundUser->getKey(),
                'counterpart_key' => $counterpartKey,
                'direction' => PromotionDirection::BundleImport,
                'status' => PromotionRunStatus::Approved,
                'selection' => array_map(
                    static fn (ArtifactDiff $entry): array => ['kind' => $entry->kind->value, 'key' => $entry->key],
                    $changed,
                ),
                'conflict_decisions' => [],
            ]);

            $this->serializer->writeTo(
                $source,
                Storage::disk('local')->path($this->sourceResolver->directoryFor($run)),
            );

            return $run;
        });
    }
}
