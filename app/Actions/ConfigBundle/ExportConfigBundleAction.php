<?php

declare(strict_types=1);

namespace App\Actions\ConfigBundle;

use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use JsonException;

class ExportConfigBundleAction
{
    private string $ability = 'config.export';

    public function __construct(
        private readonly ConfigBundleSerializer $serializer,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    public function refusalFor(User $actor): ?string
    {
        if (Gate::forUser($actor)->allows($this->ability)) {
            return null;
        }

        return __('i18n.backend.actions.config_bundle.export_config_bundle_action.you_do_not_have_the_permission', ['value1' => $this->ability]);
    }

    /**
     * @throws AuthorizationException
     * @throws AmbiguousArtifactKeyException
     * @throws JsonException
     */
    public function execute(User $actor, ConfigExportEntryPoint $entryPoint): ConfigBundle
    {
        $refusal = $this->refusalFor($actor);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        $tenantId = (string) $actor->tenant_id;
        $bundle = $this->serializer->serialize($tenantId);

        $this->auditor->recordEvent(
            Tenant::query()->whereKey($tenantId)->firstOrFail(),
            'operation.config_exported',
            [
                'entry_point' => $entryPoint->value,
                'schema_version' => $bundle->manifest->schemaVersion,
                'artifact_count' => count($bundle->artifacts),
            ],
            $tenantId,
        );

        return $bundle;
    }
}
