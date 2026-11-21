<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;

class ArtifactKindRegistry
{
    public static function identifierFor(string $kind, string $key): string
    {
        return "{$kind}:{$key}";
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $kinds = array_map(static fn (ArtifactKind $kind): string => $kind->value, ArtifactKind::cases());

        foreach (self::registered() as $kind => $entry) {
            if (in_array($kind, $kinds, true)) {
                continue;
            }

            $anchor = $entry['after'] ?? null;
            $position = is_string($anchor) ? array_search($anchor, $kinds, true) : false;

            array_splice($kinds, $position === false ? count($kinds) : $position + 1, 0, [$kind]);
        }

        return $kinds;
    }

    public static function has(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    public static function label(string $kind): string
    {
        return ArtifactKind::tryFrom($kind)?->label() ?? self::translated($kind, 'label');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::all() as $kind) {
            $labels[$kind] = self::label($kind);
        }

        return $labels;
    }

    public static function singularLabel(string $kind): string
    {
        return ArtifactKind::tryFrom($kind)?->singularLabel() ?? self::translated($kind, 'singular_label');
    }

    private static function translated(string $kind, string $field): string
    {
        $key = self::registered()[$kind][$field] ?? null;

        if (!is_string($key) || $key === '') {
            return $kind;
        }

        $translated = __($key);

        return is_string($translated) ? $translated : $kind;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function registered(): array
    {
        $configured = config('engine.config_bundle.kinds');
        $registered = [];

        foreach (is_array($configured) ? $configured : [] as $kind => $entry) {
            if (is_string($kind) && is_array($entry)) {
                $registered[$kind] = $entry;
            }
        }

        return $registered;
    }
}
