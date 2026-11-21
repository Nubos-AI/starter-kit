<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\FieldDefinition;
use App\Models\NotificationRule;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Validation\ValidationException;

class RuleTriggerFieldGuard
{
    /**
     * @var array<string, string>
     */
    private array $refusalMessages = [
        'config.date_field_key' => 'i18n.backend.support.notifications.rule_trigger_field_guard.the_date_field_is_not_available',
        'config.watched_field_keys' => 'i18n.backend.support.notifications.rule_trigger_field_guard.the_watched_fields_are_not_available',
    ];

    public function __construct(
        private readonly FieldVisibilityResolver $fieldVisibility,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @throws ValidationException
     */
    public function assertReadable(User $user, string $objectTypeId, ?array $config): void
    {
        $keysByErrorField = $this->triggerFieldKeys($config);

        if ($keysByErrorField === []) {
            return;
        }

        $refusedKeys = [
            ...$this->fieldVisibility->forbiddenReadFieldKeys($user, $objectTypeId),
            ...$this->encryptedFieldKeys($objectTypeId),
        ];

        $messages = [];

        foreach ($keysByErrorField as $errorField => $keys) {
            if (array_intersect($keys, $refusedKeys) !== []) {
                $messages[$errorField] = __($this->refusalMessages[$errorField]);
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    public function mayRead(User $recipient, NotificationRule $rule): bool
    {
        $keys = array_merge(...array_values($this->triggerFieldKeys($rule->config)));

        if ($keys === []) {
            return true;
        }

        $forbidden = $this->fieldVisibility->forbiddenReadFieldKeys($recipient, $rule->object_type_id);

        return array_intersect($keys, $forbidden) === [];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, list<string>>
     */
    private function triggerFieldKeys(?array $config): array
    {
        $dateFieldKey = $config['date_field_key'] ?? null;
        $watchedFieldKeys = $config['watched_field_keys'] ?? null;

        $keysByErrorField = [
            'config.date_field_key' => is_string($dateFieldKey) && $dateFieldKey !== '' ? [$dateFieldKey] : [],
            'config.watched_field_keys' => is_array($watchedFieldKeys)
                ? array_values(array_filter($watchedFieldKeys, static fn (mixed $key): bool => is_string($key) && $key !== ''))
                : [],
        ];

        return array_filter($keysByErrorField, static fn (array $keys): bool => $keys !== []);
    }

    /**
     * @return list<string>
     */
    private function encryptedFieldKeys(string $objectTypeId): array
    {
        return array_values($this->fieldLookup->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => (bool) $field->is_encrypted)
            ->map(static fn (FieldDefinition $field): string => $field->key)
            ->all());
    }
}
