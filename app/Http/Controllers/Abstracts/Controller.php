<?php

declare(strict_types=1);

namespace App\Http\Controllers\Abstracts;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    protected function actingUser(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.abstracts.controller.please_sign_in'));
        }

        return $user;
    }

    protected function authorizePermission(Request $request, string $permission): User
    {
        $user = $this->actingUser($request);

        if (!$user->hasPermission($permission)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.abstracts.controller.you_do_not_have_the_permission', ['value1' => $permission]));
        }

        return $user;
    }
}
