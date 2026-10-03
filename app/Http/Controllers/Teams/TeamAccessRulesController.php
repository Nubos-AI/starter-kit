<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use App\Support\Authorization\ObjectTypeAudience;
use App\Support\Engine\SystemFilterFields;
use App\Support\Teams\TeamAccessRuleGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamAccessRulesController extends Controller
{
    public function __construct(
        private readonly UpsertTeamRecordAccessRuleAction $upsertRule,
        private readonly DeleteTeamRecordAccessRuleAction $deleteRule,
        private readonly TeamAccessRuleGuard $guard,
        private readonly ObjectTypeAudience $audience,
        private readonly SystemFilterFields $systemFields,
    ) {}

    public function index(Request $request, string $team): Response
    {
        $this->authorize('viewAny', TeamRecordAccessRule::class);

        $actor = $this->actingUser($request);
        $resolvedTeam = $this->resolveTeam($actor, $team);
        $objectTypes = $this->visibleObjectTypes($actor);

        return Inertia::render('teams/AccessRules', [
            'team' => [
                'id' => $resolvedTeam->getKey(),
                'name' => $resolvedTeam->name,
            ],
            'objectTypes' => $objectTypes
                ->map(fn (ObjectType $objectType): array => [
                    'id' => $objectType->getKey(),
                    'slug' => $objectType->slug,
                    'name' => $objectType->name,
                    'fields' => $this->fieldPayload($actor, $objectType),
                ])
                ->values()
                ->all(),
            'rules' => $this->rulePayload($resolvedTeam, $objectTypes),
            'inheritedRules' => $this->inheritedRulePayload($actor, $resolvedTeam, $objectTypes),
            'inheritanceOptions' => [
                ['value' => TeamAccessRuleInheritance::Intersect->value, 'label' => __('i18n.backend.http.controllers.teams.team_access_rules_controller.in_addition_to_parent_rules')],
                ['value' => TeamAccessRuleInheritance::Override->value, 'label' => __('i18n.backend.http.controllers.teams.team_access_rules_controller.replace_parent_rules')],
            ],
        ]);
    }

    public function update(Request $request, string $team, string $objectType): RedirectResponse
    {
        $this->authorize('create', TeamRecordAccessRule::class);

        $actor = $this->actingUser($request);
        $resolvedTeam = $this->resolveTeam($actor, $team);
        $resolvedObjectType = $this->resolveObjectType($actor, $objectType);

        $this->upsertRule->execute($actor, $resolvedTeam, $resolvedObjectType, $request->all());

        return to_route('engine.teams.edit', ['team' => $resolvedTeam->getKey()]);
    }

    public function destroy(Request $request, string $team, string $objectType): RedirectResponse
    {
        $this->authorize('deleteAny', TeamRecordAccessRule::class);

        $actor = $this->actingUser($request);
        $resolvedTeam = $this->resolveTeam($actor, $team);
        $resolvedObjectType = $this->resolveObjectType($actor, $objectType);

        $this->deleteRule->execute($resolvedTeam, $resolvedObjectType);

        return to_route('engine.teams.access-rules.index', ['team' => $resolvedTeam->getKey()]);
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $objectTypes
     * @return list<array<string, mixed>>
     */
    private function rulePayload(Team $team, EloquentCollection $objectTypes): array
    {
        return TeamRecordAccessRule::query()
            ->where('team_id', $team->getKey())
            ->whereIn('object_type_id', $objectTypes->modelKeys())
            ->get()
            ->map(fn (TeamRecordAccessRule $rule): array => [
                'objectTypeId' => $rule->object_type_id,
                'filterDefinition' => $rule->filter_definition,
                'isActive' => $rule->is_active,
                'inheritance' => $rule->inheritance->value,
            ])
            ->pipe(fn ($rows): array => array_values($rows->all()));
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $objectTypes
     * @return list<array<string, mixed>>
     */
    private function inheritedRulePayload(User $actor, Team $team, EloquentCollection $objectTypes): array
    {
        $ancestorIds = $team->ancestor_team_ids;

        if ($ancestorIds === []) {
            return [];
        }

        $ancestorNames = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereKey($ancestorIds)
            ->pluck('name', 'id');

        return TeamRecordAccessRule::query()
            ->whereIn('team_id', $ancestorIds)
            ->whereIn('object_type_id', $objectTypes->modelKeys())
            ->where('is_active', true)
            ->get()
            ->map(fn (TeamRecordAccessRule $rule): array => [
                'objectTypeId' => $rule->object_type_id,
                'teamName' => (string) ($ancestorNames[$rule->team_id] ?? ''),
                'filterDefinition' => $rule->filter_definition,
                'inheritance' => $rule->inheritance->value,
            ])
            ->pipe(fn ($rows): array => array_values($rows->all()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldPayload(User $actor, ObjectType $objectType): array
    {
        $tenantId = (string) $actor->tenant_id;

        $systemFields = $this->systemFields->all(
            (string) $objectType->getKey(),
            $this->audience->userOptions($objectType, $tenantId),
            $this->audience->teamOptions($objectType, $tenantId),
        );

        $fields = $this->guard->filterableFields($objectType)
            ->reject(fn ($field): bool => $this->systemFields->isSystemField($field));

        return array_merge(
            $this->systemFields->payload($fields),
            $this->systemFields->payload($systemFields),
        );
    }

    /**
     * @return EloquentCollection<int, ObjectType>
     */
    private function visibleObjectTypes(User $actor): EloquentCollection
    {
        /** @var EloquentCollection<int, ObjectType> $types */
        $types = ObjectType::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (ObjectType $objectType): bool => $actor->hasPermission($objectType->slug.'.view'))
            ->values();

        return $types;
    }

    private function resolveObjectType(User $actor, string $objectTypeId): ObjectType
    {
        $objectType = ObjectType::query()->whereKey($objectTypeId)->firstOrFail();

        if (!$actor->hasPermission($objectType->slug.'.view')) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.teams.team_access_rules_controller.you_do_not_have_permission_for_this_object_type'));
        }

        return $objectType;
    }

    private function resolveTeam(User $actor, string $teamId): Team
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereNull('deleted_at')
            ->whereKey($teamId)
            ->firstOrFail();
    }
}
