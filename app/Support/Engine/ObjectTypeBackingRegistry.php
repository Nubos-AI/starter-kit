<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Attributes\Engine\BackedByObjectType;
use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

class ObjectTypeBackingRegistry
{
    /** @var array<string, ObjectTypeBackingInterface> */
    private array $backings = [];

    /** @var array<string, class-string>|null */
    private ?array $bindings = null;

    /** @var array<string, list<string>>|null */
    private static ?array $autoloadPrefixes = null;

    public function for(ObjectType $type): ObjectTypeBackingInterface
    {
        return $this->backings[$type->slug] ??= $this->resolve($type);
    }

    /**
     * @return array<string, class-string>
     */
    public function bindings(): array
    {
        return $this->bindings ??= [
            ...$this->scannedBindings(),
            ...$this->configuredBindings(),
        ];
    }

    public function forget(): void
    {
        $this->backings = [];
        $this->bindings = null;
    }

    private function resolve(ObjectType $type): ObjectTypeBackingInterface
    {
        $bound = $type->isGeneric() ? null : ($this->bindings()[$type->slug] ?? null);

        if ($bound === null) {
            return app(GenericRecordBacking::class);
        }

        if (is_subclass_of($bound, ObjectTypeBackingInterface::class)) {
            return app($bound);
        }

        if (!is_subclass_of($bound, Model::class)) {
            throw new MissingObjectTypeBackingException($type->slug);
        }

        return new EloquentModelBacking($bound);
    }

    /**
     * @return array<string, class-string>
     */
    private function configuredBindings(): array
    {
        $bindings = [];

        foreach ((array) config('engine.native_backings', []) as $slug => $class) {
            if (is_string($slug) && is_string($class) && class_exists($class)) {
                $bindings[$slug] = $class;
            }
        }

        return $bindings;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function scannedBindings(): array
    {
        $bindings = [];

        foreach ((array) config('engine.model_paths', []) as $path) {
            $directory = base_path((string) $path);

            if (!File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $binding = $this->bindingOf($file);

                if ($binding !== null) {
                    $bindings[$binding[0]] = $binding[1];
                }
            }
        }

        return $bindings;
    }

    /**
     * @return array{0: string, 1: class-string<Model>}|null
     */
    private function bindingOf(SplFileInfo $file): ?array
    {
        $class = $this->classOf($file);

        if ($class === null || !is_subclass_of($class, Model::class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(BackedByObjectType::class);

        if ($attributes === []) {
            return null;
        }

        return [$attributes[0]->newInstance()->slug, $class];
    }

    /**
     * @return class-string|null
     */
    private function classOf(SplFileInfo $file): ?string
    {
        $realPath = (string) $file->getRealPath();

        if (!str_ends_with($realPath, '.php')) {
            return null;
        }

        foreach ($this->autoloadPrefixes() as $namespace => $roots) {
            foreach ($roots as $root) {
                $prefix = rtrim((string) realpath($root), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

                if (!str_starts_with($realPath, $prefix)) {
                    continue;
                }

                $class = $namespace.str_replace(
                    [DIRECTORY_SEPARATOR, '.php'],
                    ['\\', ''],
                    substr($realPath, strlen($prefix)),
                );

                if (class_exists($class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function autoloadPrefixes(): array
    {
        /** @var array<string, list<string>> $prefixes */
        $prefixes = self::$autoloadPrefixes ??= require base_path('vendor/composer/autoload_psr4.php');

        return $prefixes;
    }
}
