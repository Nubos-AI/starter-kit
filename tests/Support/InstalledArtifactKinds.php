<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\Modules\ModuleCatalog;
use Composer\InstalledVersions;
use Illuminate\Support\Str;

class InstalledArtifactKinds
{
    private static string $declarationPrefix = 'engine.artifact_dependencies.';

    /**
     * @return list<ArtifactKind>
     */
    public static function all(): array
    {
        $installed = [...self::kindsOfTheCore(), ...self::kindsOfInstalledModules()];

        return array_values(array_filter(
            ArtifactKind::cases(),
            static fn (ArtifactKind $kind): bool => in_array($kind->value, $installed, true),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (ArtifactKind $kind): string => $kind->value, self::all());
    }

    /**
     * @return list<string>
     */
    private static function kindsOfTheCore(): array
    {
        /** @var array<string, mixed> $engine */
        $engine = require config_path('engine.php');

        return array_values(array_filter(
            array_keys((array) ($engine['artifact_dependencies'] ?? [])),
            'is_string',
        ));
    }

    /**
     * @return list<string>
     */
    private static function kindsOfInstalledModules(): array
    {
        return collect(app(ModuleCatalog::class)->all())
            ->map(static fn (string $module): ?string => InstalledVersions::getInstallPath($module))
            ->filter(static fn (?string $root): bool => is_string($root) && is_file("{$root}/config/contributions.php"))
            ->flatMap(static fn (string $root): array => array_keys((array) require "{$root}/config/contributions.php"))
            ->filter(static fn (mixed $key): bool => is_string($key) && str_starts_with($key, self::$declarationPrefix))
            ->map(static fn (string $key): string => Str::after($key, self::$declarationPrefix))
            ->values()
            ->all();
    }
}
