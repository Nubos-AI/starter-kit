<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    /**
     * @var array<int, array{key: string, label: string}>
     */
    private array $notificationTypes = [
        ['key' => 'reminder.due', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.reminder_due'],
        ['key' => 'rule.triggered', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.rule_triggered'],
        ['key' => 'import.completed', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.import_complete'],
        ['key' => 'export.completed', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.export_complete'],
        ['key' => 'bulk.grouped', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.bulk_action_summary'],
        ['key' => 'approval.requested', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.approval_requested'],
        ['key' => 'approval.decided', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.approval_decided'],
        ['key' => 'approval.escalated', 'label' => 'i18n.backend.http.controllers.notifications.notification_settings_controller.approval_escalated'],
    ];

    public function edit(Request $request): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('settings/Notifications', [
            'types' => array_map(static fn (array $type): array => [...$type, 'label' => __($type['label'])], $this->notificationTypes),
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
