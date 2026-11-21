<?php

declare(strict_types=1);

namespace App\Exceptions\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SelfLockoutException extends RuntimeException
{
    public static function lastEscalatedAssignmentProtected(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.self_lockout_exception.the_last_administrative_assignment_cannot_be_revoked_or_downgraded'));
    }

    public static function lastEscalatedHolderCannotBeDeleted(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.self_lockout_exception.the_last_administrator_cannot_be_deleted_promote_another_person'));
    }

    public static function actingUserWouldLoseRoleManagement(): self
    {
        return new self(__('i18n.backend.exceptions.authorization.self_lockout_exception.you_cannot_revoke_your_own_permission_to_manage_permissions'));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
