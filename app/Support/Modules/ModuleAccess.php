<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleAvailabilityInterface;
use App\Contracts\Modules\ObjectTypeModuleOwnersInterface;
use App\Enums\Modules\ModuleAccessVerdict;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

class ModuleAccess
{
    /** @var array<string, string|null> */
    private array $componentFeatures = [];

    /** @var array<string, string>|null */
    private ?array $groupModules = null;

    /** @var array<string, string>|null */
    private ?array $webhookEventModules = null;

    /** @var array<string, Tenant|null> */
    private array $tenants = [];

    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly ModuleAvailabilityInterface $availability,
        private readonly ModuleConfiguration $configuration,
        private readonly ?ObjectTypeModuleOwnersInterface $objectTypeOwners = null,
    ) {}

    /**
     * @param  object|class-string  $component
     */
    public function allows(object|string $component, ?User $user = null): bool
    {
        $module = $this->catalog->owner($component);

        if ($module === null) {
            return true;
        }

        return $this->allowsModule($module, $this->featureOfComponent($module, $component), $user);
    }

    /**
     * @param  object|class-string  $component
     */
    public function allowsFor(object|string $component, ?Tenant $tenant): bool
    {
        $module = $this->catalog->owner($component);

        if ($module === null) {
            return true;
        }

        return $this->allowsModuleFor($module, $this->featureOfComponent($module, $component), $tenant);
    }

    /**
     * @param  object|class-string  $component
     */
    public function allowsForTenantId(object|string $component, string $tenantId): bool
    {
        if ($this->catalog->owner($component) === null) {
            return true;
        }

        return $this->allowsFor($component, $this->tenantOf($tenantId));
    }

    public function allowsModule(string $module, ?string $feature = null, ?User $user = null): bool
    {
        if (!$this->allowsModuleFor($module, $feature, TenantContext::current())) {
            return false;
        }

        return $user === null || $this->availability->isUsableBy($module, $feature, $user);
    }

    public function allowsModuleFor(string $module, ?string $feature, ?Tenant $tenant): bool
    {
        if ($feature !== null && !$this->configuration->enabled($module, $feature)) {
            return false;
        }

        return $this->availability->isAvailable($module, $feature, $tenant);
    }

    public function allowsObjectType(ObjectType $objectType): bool
    {
        $modules = $this->objectTypeOwners?->modulesOf($objectType) ?? [];

        if ($modules === []) {
            return true;
        }

        $tenant = $this->tenantOf((string) $objectType->tenant_id);

        foreach ($modules as $module) {
            if ($this->allowsModuleFor($module, null, $tenant)) {
                return true;
            }
        }

        return false;
    }

    public function allowsPermissionGroup(string $group, ?Tenant $tenant): bool
    {
        $module = $this->groupModules()[$group] ?? null;

        return $module === null || $this->allowsModuleFor($module, null, $tenant);
    }

    public function allowsWebhookEvent(string $event, ?Tenant $tenant): bool
    {
        $module = $this->webhookEventModules()[$event] ?? null;

        return $module === null || $this->allowsModuleFor($module, null, $tenant);
    }

    public function allowsWebhookEventForTenantId(string $event, string $tenantId): bool
    {
        if (!array_key_exists($event, $this->webhookEventModules())) {
            return true;
        }

        return $this->allowsWebhookEvent($event, $this->tenantOf($tenantId));
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    public function availableWebhookEvents(array $events, ?Tenant $tenant): array
    {
        return array_values(array_filter($events, fn (string $event): bool => $this->allowsWebhookEvent($event, $tenant)));
    }

    public function routeAllowsTenant(?string $routeName, ?string $controller, Tenant $tenant): bool
    {
        [$module, $feature] = $this->routeOwner($routeName, $controller);

        return $module === null || $this->allowsModuleFor($module, $feature, $tenant);
    }

    public function publicRouteVerdict(?string $routeName, ?string $controller): ModuleAccessVerdict
    {
        [$module, $feature] = $this->routeOwner($routeName, $controller);

        return $module === null || $this->allowsModuleFor($module, $feature, null)
            ? ModuleAccessVerdict::Allowed
            : ModuleAccessVerdict::Unavailable;
    }

    public function routeVerdict(?string $routeName, ?string $controller, ?User $user): ModuleAccessVerdict
    {
        [$module, $feature] = $this->routeOwner($routeName, $controller);

        if ($module === null) {
            return ModuleAccessVerdict::Allowed;
        }

        if (!$this->allowsModuleFor($module, $feature, TenantContext::current())) {
            return ModuleAccessVerdict::Unavailable;
        }

        if ($user !== null && !$this->availability->isUsableBy($module, $feature, $user)) {
            return ModuleAccessVerdict::Forbidden;
        }

        return ModuleAccessVerdict::Allowed;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function routeOwner(?string $routeName, ?string $controller): array
    {
        if ($controller !== null && class_exists($controller)) {
            $module = $this->catalog->owner($controller);

            if ($module !== null) {
                return [$module, $this->featureOfRoute($module, $routeName) ?? $this->featureOfComponent($module, $controller)];
            }
        }

        foreach ($this->catalog->all() as $candidate) {
            $feature = $this->featureOfRoute($candidate, $routeName);

            if ($feature !== null) {
                return [$candidate, $feature];
            }
        }

        return [null, null];
    }

    /**
     * @param  object|class-string  $component
     */
    private function featureOfComponent(string $module, object|string $component): ?string
    {
        $class = is_object($component) ? $component::class : $component;

        if (array_key_exists($class, $this->componentFeatures)) {
            return $this->componentFeatures[$class];
        }

        foreach ($this->features($module) as $feature => $parts) {
            foreach ($parts['components'] ?? [] as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    return $this->componentFeatures[$class] = $feature;
                }
            }
        }

        return $this->componentFeatures[$class] = null;
    }

    private function featureOfRoute(string $module, ?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        foreach ($this->features($module) as $feature => $parts) {
            if (Str::is($parts['routes'] ?? [], $routeName)) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{routes?: list<string>, components?: list<string>}>
     */
    private function features(string $module): array
    {
        /** @var array<string, array{routes?: list<string>, components?: list<string>}> $features */
        $features = $this->catalog->manifest($module)['features'] ?? [];

        return $features;
    }

    /**
     * @return array<string, string>
     */
    private function groupModules(): array
    {
        if ($this->groupModules !== null) {
            return $this->groupModules;
        }

        $groups = [];

        foreach (config('permissions.groups', []) as $group => $meta) {
            if (is_string($group) && is_array($meta) && is_string($meta['module'] ?? null)) {
                $groups[$group] = $meta['module'];
            }
        }

        foreach ($this->catalog->all() as $module) {
            /** @var list<array{value: string}> $offered */
            $offered = $this->catalog->manifest($module)['options']['permissions.groups'] ?? [];

            foreach ($offered as $group) {
                $groups[$group['value']] = $module;
            }
        }

        return $this->groupModules = $groups;
    }

    /**
     * @return array<string, string>
     */
    private function webhookEventModules(): array
    {
        if ($this->webhookEventModules !== null) {
            return $this->webhookEventModules;
        }

        $events = [];

        foreach ($this->catalog->all() as $module) {
            /** @var list<string> $declared */
            $declared = $this->catalog->manifest($module)['webhook_events'] ?? [];

            foreach ($declared as $event) {
                $events[$event] = $module;
            }
        }

        return $this->webhookEventModules = $events;
    }

    private function tenantOf(string $tenantId): ?Tenant
    {
        $current = TenantContext::current();

        if ($current !== null && (string) $current->getKey() === $tenantId) {
            return $current;
        }

        if (!array_key_exists($tenantId, $this->tenants)) {
            $this->tenants[$tenantId] = Tenant::query()->find($tenantId);
        }

        return $this->tenants[$tenantId];
    }
}
