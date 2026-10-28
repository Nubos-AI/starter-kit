<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Api\BulkRevokeApiTokensAction;
use App\Actions\Api\CreateApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Actions\Api\RotateApiTokenAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiTokenPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensController extends Controller
{
    public function __construct(
        private readonly CreateApiTokenAction $createApiToken,
        private readonly RotateApiTokenAction $rotateApiToken,
        private readonly RevokeApiTokenAction $revokeApiToken,
        private readonly BulkRevokeApiTokensAction $bulkRevokeApiTokens,
        private readonly ApiTokenPresenter $presenter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tenant = $this->authorizedTenant($request);
        $secret = $request->session()->pull('apiTokenSecret');

        return Inertia::render('apiTokens/Index', [
            'tokens' => $this->presenter->presentMany($this->tenantTokens($tenant)),
            'secret' => is_string($secret) ? $secret : null,
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $this->authorizedTenant($request);

        return Inertia::render('apiTokens/Form', [
            'objectTypeOptions' => $this->objectTypeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $token = $this->createApiToken->execute($this->actingUser($request), $tenant, $request->all());

        return to_route('engine.api-tokens.index')->with('apiTokenSecret', $token->plainTextToken);
    }

    public function rotate(Request $request, string $token): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);
        $owner = $this->tokenOwnerInTenant($tenant, $token);

        if (!$owner->is_service) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.api.api_tokens_controller.only_the_owner_of_a_personal_api_token_may'));
        }

        $rotated = $this->rotateApiToken->execute($owner, $token);

        return to_route('engine.api-tokens.index')->with('apiTokenSecret', $rotated->plainTextToken);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $this->revokeApiToken->execute($this->tokenOwnerInTenant($tenant, $token), $token);

        return to_route('engine.api-tokens.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizedTenant($request);

        $this->bulkRevokeApiTokens->execute($this->actingUser($request), $request->all());

        return to_route('engine.api-tokens.index');
    }

    private function authorizedTenant(Request $request): Tenant
    {
        $user = $request->user();

        if (!$user instanceof User || !$this->mayManageTokens($user)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.api.api_tokens_controller.you_may_not_manage_api_tokens'));
        }

        $tenant = $user->tenant;

        if (!$tenant instanceof Tenant) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.api.api_tokens_controller.managing_api_tokens_requires_a_tenant_context'));
        }

        return $tenant;
    }

    private function mayManageTokens(User $user): bool
    {
        return $user->isEscalatedAuthority() || $user->hasPermission('api-tokens.manage');
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    private function tenantTokens(Tenant $tenant): Collection
    {
        return $this->tenantTokenQuery($tenant)->orderByDesc('created_at')->get();
    }

    /**
     * @return Builder<PersonalAccessToken>
     */
    private function tenantTokenQuery(Tenant $tenant): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $this->tenantUserIds($tenant))
            ->with('tokenable');
    }

    /**
     * @return array<int, string>
     */
    private function tenantUserIds(Tenant $tenant): array
    {
        return User::query()
            ->where('tenant_id', $tenant->getKey())
            ->pluck('id')
            ->all();
    }

    private function tokenOwnerInTenant(Tenant $tenant, string $tokenId): User
    {
        $token = PersonalAccessToken::query()->whereKey($tokenId)->first();
        $owner = $token?->tokenable;

        if (!$owner instanceof User || $owner->tenant_id !== $tenant->getKey()) {
            throw (new ModelNotFoundException)->setModel(PersonalAccessToken::class, [$tokenId]);
        }

        return $owner;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function objectTypeOptions(): array
    {
        return ObjectType::query()
            ->where('is_system', false)
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectType $type): array => [
                'value' => $type->slug,
                'label' => $type->name,
            ])
            ->all();
    }
}
