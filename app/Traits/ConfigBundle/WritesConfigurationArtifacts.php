<?php

declare(strict_types=1);

namespace App\Traits\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

trait WritesConfigurationArtifacts
{
    use ReportsArtifactWriteResults;
    use ResolvesArtifactTargets;

    /**
     * @param  Closure(): ArtifactWriteResult  $work
     *
     * @throws AuthorizationException
     */
    private function runAs(User $actingUser, Closure $work): ArtifactWriteResult
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            throw new AuthorizationException(__('i18n.backend.traits.config_bundle.writes_configuration_artifacts.bundle_artifacts_can_only_be_written_into_a_bound'));
        }

        /** @var list<ArtifactWriteResult> $results */
        $results = [];

        $this->actingUserContext->run($tenantId, (string) $actingUser->getKey(), static function () use ($work, &$results): void {
            $results[] = $work();
        });

        return $results[0] ?? throw new AuthorizationException(__('i18n.backend.traits.config_bundle.writes_configuration_artifacts.bundle_artifacts_can_only_be_written_inside_an_acting'));
    }

    private function manageabilityReason(ObjectType $objectType): ?string
    {
        try {
            $this->systemObjectTypeGuard->assertFieldsAreManageable($objectType);
        } catch (AuthorizationException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
