<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        Inertia::clearHistory();

        return $request->wantsJson()
            ? new JsonResponse('', Response::HTTP_NO_CONTENT)
            : new RedirectResponse(Fortify::redirects('logout', '/'));
    }
}
