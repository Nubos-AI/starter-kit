<?php

declare(strict_types=1);

namespace App\Exceptions\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RoleInUseException extends RuntimeException
{
    public static function hasAssignedUsers(int $userCount): self
    {
        $suffix = $userCount === 1 ? __('i18n.backend.exceptions.authorization.role_in_use_exception.assigned_to_one_person') : $userCount.__('i18n.backend.exceptions.authorization.role_in_use_exception.people_assigned');

        return new self(__('i18n.backend.exceptions.authorization.role_in_use_exception.this_role_still_has').$suffix.__('i18n.backend.exceptions.authorization.role_in_use_exception.and_cannot_be_deleted'));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
