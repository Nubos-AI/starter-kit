<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\Notifications\NotificationTypeCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function __construct(private readonly NotificationTypeCatalog $notificationTypes) {}

    public function edit(Request $request): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('settings/Notifications', [
            'types' => $this->notificationTypes->options(),
            'notificationPreferences' => $this->preferences($user),
            'defaults' => $user->isEscalatedAuthority() ? $this->tenantDefaults($user) : [],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tenantDefaults(User $user): array
    {
        return NotificationTypeDefault::query()
            ->where('tenant_id', $user->tenant_id)
            ->get()
            ->groupBy('type')
            ->map(fn ($group): array => $group
                ->mapWithKeys(fn (NotificationTypeDefault $default): array => [
                    $default->channel->value => [
                        'enabled' => $default->enabled,
                        'delivery_mode' => $default->delivery_mode->value,
                    ],
                ])
                ->all())
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function preferences(User $user): array
    {
        return UserNotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->groupBy('type')
            ->map(fn ($group): array => $group
                ->mapWithKeys(fn (UserNotificationPreference $preference): array => [
                    $preference->channel->value => [
                        'enabled' => $preference->enabled,
                        'delivery_mode' => $preference->delivery_mode->value,
                    ],
                ])
                ->all())
            ->all();
    }
}
