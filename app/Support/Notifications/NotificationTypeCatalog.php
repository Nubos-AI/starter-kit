<?php

declare(strict_types=1);

namespace App\Support\Notifications;

class NotificationTypeCatalog
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            static fn (array $type): array => ['key' => $type['key'], 'label' => (string) __($type['label'])],
            $this->types(),
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_column($this->types(), 'key');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function types(): array
    {
        $entries = config('notifications.types', []);

        if (!is_array($entries)) {
            return [];
        }

        $types = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;
            $label = $entry['label'] ?? null;

            if (!is_string($key) || $key === '' || !is_string($label) || $label === '') {
                continue;
            }

            $types[$key] = ['key' => $key, 'label' => $label];
        }

        return array_values($types);
    }
}
