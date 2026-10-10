<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\UiModuleInterface;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use LogicException;

class UiModuleRegistry
{
    /** @var array<string, UiModuleInterface> */
    private array $modules = [];

    /** @var list<Closure(string, ?User): bool> */
    private array $filters = [];

    /** @param iterable<UiModuleInterface> $modules */
    public function __construct(
        iterable $modules,
        private readonly ModuleRegistry $registry,
        private readonly ModuleCatalog $catalog,
    ) {
        foreach ($modules as $module) {
            if (isset($this->modules[$module->key()])) {
                throw new LogicException("Duplicate UI module [{$module->key()}].");
            }

            $this->modules[$module->key()] = $module;
        }
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $names = $this->visibleNames($request->user());
        $shared = [
            'uiModules' => $names,
            'uiExtensions' => $this->extensions($names),
            'uiOptions' => $this->options($names),
            'uiExtensionOverrides' => config('modules.extensions', []),
        ];

        foreach ($this->visibleModules($names) as $module) {
            foreach ($module->share($request) as $key => $value) {
                if (array_key_exists($key, $shared)) {
                    throw new LogicException("Duplicate shared module property [{$key}].");
                }

                $shared[$key] = $value;
            }
        }

        return $shared;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, bool>  $permissions
     * @return array<string, mixed>
     */
    public function extendNavigation(array $tree, User $user, array $permissions): array
    {
        $overrides = [];
        foreach ($this->visibleModules($this->visibleNames($user)) as $module) {
            foreach ($module->navigation($user, $permissions) as $contribution) {
                $target = $contribution['target'];
                $items = $contribution['items'];

                if ($target === 'navigation.overrides') {
                    foreach ($items as $item) {
                        $overrides[$item['key']] = $item;
                    }

                    continue;
                }

                if ($target === 'navigation.main' || $target === 'navigation.configuration') {
                    $section = substr($target, strlen('navigation.'));
                    array_push($tree[$section], ...$items);

                    continue;
                }

                $key = str_starts_with($target, 'navigation.node.') ? substr($target, strlen('navigation.node.')) : '';
                $found = false;
                foreach (['main', 'configuration'] as $section) {
                    $tree[$section] = $this->appendToNode($tree[$section], $key, $items, $found);
                }

                if (!$found) {
                    throw new LogicException("Unknown navigation extension target [{$target}].");
                }
            }
        }

        $customized = $this->customizeNavigation($tree, $overrides);
        $customized['main'] = array_values(array_filter(
            $customized['main'],
            static fn (mixed $section): bool => !is_array($section) || ($section['items'] ?? null) !== [],
        ));

        return $customized;
    }

    /** @param Closure(string, ?User): bool $filter */
    public function filterUsing(Closure $filter): void
    {
        $this->filters[] = $filter;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, UiModuleInterface>
     */
    private function visibleModules(array $names): array
    {
        return array_intersect_key($this->modules, array_flip($names));
    }

    /** @return list<string> */
    private function visibleNames(?User $user): array
    {
        return array_values(array_filter($this->registry->all(), function (string $name) use ($user): bool {
            if (!$this->catalog->has($name)) {
                return false;
            }

            foreach ($this->filters as $filter) {
                if (!$filter($name, $user)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function extensions(array $names): array
    {
        $extensions = [];

        foreach ($names as $name) {
            /** @var list<array{id: string}&array<string, mixed>> $contributions */
            $contributions = $this->catalog->manifest($name)['extensions'] ?? [];

            foreach ($contributions as $extension) {
                if (isset($extensions[$extension['id']])) {
                    throw new LogicException("Duplicate UI extension [{$extension['id']}].");
                }

                $extensions[$extension['id']] = [...$extension, 'module' => $name];
            }
        }

        return array_values($extensions);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(array $names): array
    {
        $options = [];

        foreach ($names as $name) {
            /** @var array<string, list<array{value: string, label: string}>> $contributions */
            $contributions = $this->catalog->manifest($name)['options'] ?? [];

            foreach ($contributions as $point => $entries) {
                $options[$point] = [...$options[$point] ?? [], ...$entries];
            }
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  list<array<string, mixed>>  $items
     * @return array<array-key, mixed>
     */
    private function appendToNode(array $tree, string $key, array $items, bool &$found): array
    {
        if (($tree['key'] ?? null) === $key) {
            $tree['children'] = [...($tree['children'] ?? []), ...$items];
            $found = true;

            return $tree;
        }

        foreach ($tree as $index => $value) {
            if (is_array($value)) {
                $tree[$index] = $this->appendToNode($value, $key, $items, $found);
            }
        }

        return $tree;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array<array-key, mixed>
     */
    private function customizeNavigation(array $tree, array $overrides = []): array
    {
        foreach ($tree as $index => $node) {
            if (!is_array($node)) {
                continue;
            }

            $override = isset($node['key']) ? array_replace(config('modules.navigation', [])[$node['key']] ?? [], $overrides[$node['key']] ?? []) : [];

            if (($override['enabled'] ?? true) === false) {
                unset($tree[$index]);

                continue;
            }

            $tree[$index] = $this->customizeNavigation(array_replace($node, $override), $overrides);
        }

        if (!array_filter(array_keys($tree), 'is_string')) {
            $tree = array_values($tree);
            usort($tree, fn (mixed $first, mixed $second): int => (is_array($first) ? ($first['order'] ?? 100) : 100) <=> (is_array($second) ? ($second['order'] ?? 100) : 100));
        }

        return $tree;
    }
}
