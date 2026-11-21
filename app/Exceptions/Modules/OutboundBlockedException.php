<?php

declare(strict_types=1);

namespace App\Exceptions\Modules;

use App\Enums\Ui\ToastType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OutboundBlockedException extends RuntimeException
{
    public function __construct(public readonly string $target, ?string $message = null)
    {
        parent::__construct($message ?? "Outbound delivery to [{$target}] is blocked by the active context.");
    }

    public function userMessage(): string
    {
        return __('i18n.backend.exceptions.modules.outbound_blocked_exception.outbound_connections_are_blocked_in_this_environment');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse(['message' => $this->userMessage()], Response::HTTP_CONFLICT);
        }

        Inertia::flash('toast', ['type' => ToastType::Error->value, 'message' => $this->userMessage()]);

        return back();
    }
}
