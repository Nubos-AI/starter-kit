<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\UpdateNotificationPreferencesAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\NotificationTypeDefault;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationPreferencesController extends Controller
{
    use RespondsWithValidationErrors;

    public function __construct(private readonly UpdateNotificationPreferencesAction $action) {}

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->actingUser($request);
        $viaInertia = $request->hasHeader('X-Inertia');

        try {
            $state = $this->action->execute($user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationOutcome($exception, $viaInertia);
        }

        if ($viaInertia) {
            return back();
        }

        return new JsonResponse([
            'frequency' => $state->frequency?->value,
            'hour' => $state->hour,
        ]);
    }

    public function updateTenantDefaults(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->actingUser($request);
        $viaInertia = $request->hasHeader('X-Inertia');

        if (!$user->isEscalatedAuthority()) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.notifications.notification_preferences_controller.you_may_not_manage_tenant_wide_notification_defaults'));
        }

        try {
            $defaults = $this->action->writeTenantDefaults($user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationOutcome($exception, $viaInertia);
        }

        if ($viaInertia) {
            return back();
        }

        return new JsonResponse([
            'defaults' => array_map(fn (NotificationTypeDefault $default): array => [
                'type' => $default->type,
                'channel' => $default->channel->value,
                'enabled' => $default->enabled,
                'delivery_mode' => $default->delivery_mode->value,
            ], $defaults),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function validationOutcome(ValidationException $exception, bool $viaInertia): JsonResponse
    {
        if ($viaInertia) {
            throw $exception;
        }

        return $this->validationResponse($exception);
    }
}
