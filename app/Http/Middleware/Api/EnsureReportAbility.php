<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\ApiTokenAbility;
use App\Models\Goal;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportAbility
{
    public function __construct(private readonly ApiAbilityMap $abilityMap) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$user instanceof User || !$token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $objectType = $this->objectTypeFor($user, $request);

        $satisfied = $this->abilityMap->satisfiesObjectType($token, $objectType, ApiAccessLevel::Read);

        if (!$satisfied) {
            throw new MissingAbilityException([
                ApiTokenAbility::forLevel(ApiAccessLevel::Read)->value,
                $this->abilityMap->abilityFor($objectType->slug, ApiAccessLevel::Read),
            ]);
        }

        return $next($request);
    }

    private function objectTypeFor(User $user, Request $request): ObjectType
    {
        $reportId = $request->route('report');

        if (is_string($reportId)) {
            $report = Report::query()
                ->with('objectType')
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($reportId)
                ->first();

            return $this->objectTypeOf($report, Report::class, $reportId);
        }

        $goalId = $request->route('goal');

        if (is_string($goalId)) {
            $goal = Goal::query()
                ->with('report.objectType')
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($goalId)
                ->first();

            return $this->objectTypeOf($goal?->report, Goal::class, $goalId);
        }

        throw new LogicException(__('i18n.backend.http.middleware.api.ensure_report_ability.ensure_report_ability_requires_a_report_or_goal_route'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function objectTypeOf(?Report $report, string $model, string $identifier): ObjectType
    {
        $objectType = $report?->objectType;

        if (!$objectType instanceof ObjectType) {
            throw (new ModelNotFoundException)->setModel($model, [$identifier]);
        }

        return $objectType;
    }
}
