<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ConfigBundle\ArtifactKind;
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
        $absent = self::kindsOfAbsentModules();

        return array_values(array_filter(
            ArtifactKind::cases(),
            static fn (ArtifactKind $kind): bool => !in_array($kind->value, $absent, true),
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
    private static function kindsOfAbsentModules(): array
    {
        return collect(glob(base_path('packages/nubos/*/config/contributions.php')) ?: [])
            ->reject(static fn (string $path): bool => InstalledVersions::isInstalled('nubos/'.basename(dirname($path, 2))))
            ->flatMap(static fn (string $path): array => array_keys((array) require $path))
            ->filter(static fn (mixed $key): bool => is_string($key) && str_starts_with($key, self::$declarationPrefix))
            ->map(static fn (string $key): string => Str::after($key, self::$declarationPrefix))
            ->values()
            ->all();
    }
}
