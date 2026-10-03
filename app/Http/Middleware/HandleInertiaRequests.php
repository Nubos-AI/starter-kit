<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Authorization\RoleAuthority;
use App\Models\ObjectType;
use App\Models\ReminderType;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Authorization\EffectivePermissionMap;
use App\Support\Localization\TranslationCatalogue;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Modules\UiModuleRegistry;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    /**
     * @var array<string, bool>|null
     */
    private ?array $permissions = null;

    /**
     * @var Collection<int, ObjectType>|null
     */
    private ?Collection $objectTypes = null;

    public function __construct(
        private readonly NavigationBuilder $navigation,
        private readonly UiModuleRegistry $modules,
        private readonly CurrentTeamResolver $currentTeamResolver,
        private readonly EffectivePermissionMap $permissionMap,
        private readonly UserPreferenceResolver $preferenceResolver,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly TranslationCatalogue $translations,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'i18n' => fn (): array => $this->translations->forLocale(app()->getLocale()),
            'contextKey' => fn (): string => TenantContext::currentId() ?? 'default',
            'auth' => fn (): array => $this->auth($request),
            'navigation' => fn (): array => $this->navigationTree($request),
            'currentTeam' => fn (): ?array => $this->currentTeam($request),
            'availableTeams' => fn (): array => $this->availableTeams($request),
            'reminderTypeOptions' => fn (): array => $this->reminderTypeOptions($request),
            ...$this->modules->share($request),
            'maintenance' => fn (): ?array => $this->maintenance(),
            'sidebarOpen' => !$request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'preferences' => fn (): ?array => $this->preferences($request),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        return [
            'user' => $user instanceof User ? $this->sharedUser($user) : null,
            'can' => $user instanceof User ? $this->permissions($user) : [],
            'authority' => $user instanceof User ? $this->authority($user) : null,
        ];
    }

    /**
     * @return array{id: string, salutation: string, first_name: string|null, last_name: string|null, name: string, email: string, email_verified_at: string|null, created_at: string|null, updated_at: string|null}
     */
    private function sharedUser(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'salutation' => $user->salutation->value,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toJSON(),
            'created_at' => $user->created_at?->toJSON(),
            'updated_at' => $user->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preferences(Request $request): ?array
    {
        $user = $request->user();

        return $user instanceof User ? $this->preferenceResolver->sharedDocument($user) : null;
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(User $user): array
    {
        return $this->permissions ??= $this->permissionMap->forUser($user, $this->objectTypes());
    }

    private function authority(User $user): ?string
    {
        foreach (RoleAuthority::cases() as $authority) {
            if ($user->hasRoleWithAuthority($authority)) {
                return $authority->value;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, ObjectType>
     */
    private function objectTypes(): Collection
    {
        return $this->objectTypes ??= ObjectType::query()->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationTree(Request $request): array
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return [];
        }

        return $this->navigation->build($user, $this->objectTypes(), $this->permissions($user));
    }

    /**
     * @return array{id: string, name: string, slug: string}|null
     */
    private function currentTeam(Request $request): ?array
    {
        $user = $this->sessionUser($request);

        if (!$user instanceof User) {
            return null;
        }

        $team = $this->currentTeamResolver->resolve($user);

        return $team === null ? null : [
            'id' => (string) $team->getKey(),
            'name' => $team->name,
            'slug' => $team->slug,
        ];
    }

    /**
     * @return array<int, array{id: string, name: string, slug: string}>
     */
    private function availableTeams(Request $request): array
    {
        $user = $this->sessionUser($request);

        if (!$user instanceof User) {
            return [];
        }

        return $user->teams()
            ->withoutGlobalScopes()
            ->whereNull('teams.deleted_at')
            ->orderBy('teams.name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => (string) $team->getKey(),
                'name' => $team->name,
                'slug' => $team->slug,
            ])
            ->values()
            ->all();
    }

    private function sessionUser(Request $request): ?User
    {
        $user = $request->attributes->get('context_user') ?? $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array{tenantName: string, since: string, reason: string}|null
     */
    private function maintenance(): ?array
    {
        $tenant = TenantContext::current();

        if ($tenant === null) {
            return null;
        }

        $lock = $this->maintenanceLocks->activeFor((string) $tenant->getKey());

        if ($lock === null) {
            return null;
        }

        return [
            'tenantName' => $tenant->name,
            'since' => $lock->acquired_at->toIso8601String(),
            'reason' => $lock->reason->label(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function reminderTypeOptions(Request $request): array
    {
        if (!$request->user() instanceof User) {
            return [];
        }

        return ReminderType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ReminderType $type): array => ['value' => $type->id, 'label' => $type->name])
            ->all();
    }
}
