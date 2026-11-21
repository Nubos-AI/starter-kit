<?php

declare(strict_types=1);

namespace App\Exceptions\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class TeamHierarchyException extends RuntimeException
{
    public static function cannotParentToSelf(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.a_team_cannot_be_its_own_parent'));
    }

    public static function cannotParentToDescendant(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.a_team_cannot_be_moved_under_one_of_its'));
    }

    public static function parentNotFound(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_selected_parent_team_does_not_exist'));
    }

    public static function teamNotFound(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_team_to_move_no_longer_exists'));
    }

    public static function parentInDifferentTenant(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_selected_parent_team_belongs_to_a_different_tenant'));
    }

    public static function parentIsTrashed(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_selected_parent_team_is_deleted_and_cannot_accept'));
    }

    public static function reparentRequiresMaterializer(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_parent_team_can_only_be_changed_through_the'));
    }

    public static function scopeIsImmutable(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_tenant_of_an_existing_team_cannot_be_changed'));
    }

    public static function tenantTreeTooLarge(int $nodeCount): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.this_tenant_s_team_tree_contains').$nodeCount.__('i18n.backend.exceptions.authorization.team_hierarchy_exception.teams_and_exceeds_the_supported_size'));
    }

    public static function cycleDetected(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.team_hierarchy_exception.the_saved_team_tree_contains_a_cycle_and_cannot'));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
