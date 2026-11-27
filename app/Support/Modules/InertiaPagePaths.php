<?php

declare(strict_types=1);

namespace App\Support\Modules;

class InertiaPagePaths
{
    /**
     * @return list<string>
     */
    public static function discover(string $javascriptRoot): array
    {
        $paths = [
            ...(glob("{$javascriptRoot}/domains/*/*/pages", GLOB_ONLYDIR) ?: []),
            ...(glob("{$javascriptRoot}/platform/*/pages", GLOB_ONLYDIR) ?: []),
            ...(glob("{$javascriptRoot}/app-shell/pages", GLOB_ONLYDIR) ?: []),
        ];

        sort($paths);

        return $paths;
    }

    public static function register(string $javascriptRoot): void
    {
        config(['inertia.pages.paths' => array_values(array_unique([
            ...config('inertia.pages.paths', []),
            ...self::discover($javascriptRoot),
        ]))]);
    }
}
