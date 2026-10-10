<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Localization\JsonTranslationLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;

class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('translation.loader', function (FileLoader $original, Application $app): JsonTranslationLoader {
            $loader = new JsonTranslationLoader($app->make(Filesystem::class), [...$original->paths(), resource_path('lang')]);

            foreach ($original->namespaces() as $namespace => $path) {
                $loader->addNamespace($namespace, $path);
            }

            foreach ($original->jsonPaths() as $path) {
                $loader->addJsonPath($path);
            }

            return $loader;
        });
    }
}
