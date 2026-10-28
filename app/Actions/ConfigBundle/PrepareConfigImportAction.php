<?php

declare(strict_types=1);

namespace App\Actions\ConfigBundle;

use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UnsafeArchiveException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\BundleArchiveExtractor;
use App\Support\ConfigBundle\ConfigBundleReader;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Promotion\PromotionSourceResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PrepareConfigImportAction
{
    private string $ability = 'config.import';

    private int $counterpartLabelLength = 240;

    public function __construct(
        private readonly BundleArchiveExtractor $extractor,
        private readonly ConfigBundleReader $reader,
        private readonly ConfigBundleSerializer $serializer,
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    public function refusalFor(User $actor): ?string
    {
        if (!Gate::forUser($actor)->allows($this->ability)) {
            return __('i18n.backend.actions.config_bundle.prepare_config_import_action.you_do_not_have_the_permission', ['value1' => $this->ability]);
        }

        if (!$actor->isEscalatedAuthority()) {
            return __('i18n.backend.actions.config_bundle.prepare_config_import_action.the_import_writes_to_this_tenant_s_configuration_and');
        }

        return null;
    }

    /**
     * @throws AuthorizationException
     * @throws UnsafeArchiveException
     * @throws MalformedBundleException
     * @throws UnsupportedBundleSchemaVersionException
     * @throws Throwable
     */
    public function execute(User $actor, string $absoluteZipPath): PromotionRun
    {
        $refusal = $this->refusalFor($actor);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        $scratch = storage_path('app/private/tmp/config-import-'.Str::ulid());
        File::ensureDirectoryExists($scratch);

        try {
            $this->extractor->extract($absoluteZipPath, $scratch);

            $bundle = $this->read($actor, $scratch);

            return DB::transaction(fn (): PromotionRun => $this->store($actor, $bundle));
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * @throws MalformedBundleException
     */
    private function read(User $actor, string $directory): ConfigBundle
    {
        try {
            return $this->reader->readFrom($directory);
        } catch (Throwable $exception) {
            if (!$exception instanceof InvalidArgumentException) {
                throw $exception;
            }

            Log::warning('Uploaded config bundle rejected as incomplete.', [
                'tenant_id' => (string) $actor->tenant_id,
                'reason' => $exception->getMessage(),
            ]);

            throw new MalformedBundleException(__('i18n.backend.actions.config_bundle.prepare_config_import_action.this_bundle_is_incomplete_or_damaged'), null, [], $exception);
        }
    }

    /**
     * @throws Throwable
     */
    private function store(User $actor, ConfigBundle $bundle): PromotionRun
    {
        $tenantId = (string) $actor->tenant_id;
        $sourceLabel = Str::limit($bundle->manifest->sourceLabel, $this->counterpartLabelLength, '');

        $run = PromotionRun::query()->create([
            'tenant_id' => $tenantId,
            'source_tenant_id' => null,
            'triggered_by_id' => (string) $actor->getKey(),
            'counterpart_key' => "bundle:{$sourceLabel}",
            'direction' => PromotionDirection::BundleImport,
            'status' => PromotionRunStatus::Draft,
            'selection' => [],
            'conflict_decisions' => [],
        ]);

        $directory = $this->sourceResolver->directoryFor($run);

        try {
            $this->serializer->writeTo($bundle, Storage::disk('local')->path($directory));

            $this->auditor->recordEvent($run, 'operation.config_import_prepared', [
                'source_label' => $bundle->manifest->sourceLabel,
                'artifact_count' => count($bundle->artifacts),
            ], $tenantId);
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory($directory);

            throw $exception;
        }

        return $run;
    }
}
