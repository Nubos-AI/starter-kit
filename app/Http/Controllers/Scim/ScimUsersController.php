<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Scim\CreateScimUserAction;
use App\Actions\Scim\DeleteScimUserAction;
use App\Actions\Scim\UpdateScimUserAction;
use App\Exceptions\Scim\ScimException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Scim\ScimUserResource;
use App\Models\User;
use App\Support\Scim\ScimFilterParser;
use App\Support\Scim\ScimPatchParser;
use App\Support\Scim\ScimResponder;
use App\Support\Scim\ScimUserDirectory;
use App\Support\Scim\ScimUserMapper;
use App\Traits\Scim\ResolvesScimTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ScimUsersController extends Controller
{
    use ResolvesScimTenant;

    public function __construct(
        private readonly ScimUserDirectory $users,
        private readonly ScimFilterParser $filters,
        private readonly ScimPatchParser $patches,
        private readonly ScimUserMapper $mapper,
        private readonly ScimResponder $responder,
        private readonly CreateScimUserAction $createUser,
        private readonly UpdateScimUserAction $updateUser,
        private readonly DeleteScimUserAction $deleteUser,
    ) {}

    /**
     * @throws ScimException
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);
        $filter = $this->filters->parse($request->query('filter') === null ? null : (string) $request->query('filter'), ScimUserDirectory::$filterableAttributes);
        [$startIndex, $limit] = $this->scimPage($request);

        [$total, $users] = $this->users->page($tenantId, $filter, $startIndex - 1, $limit);

        return $this->responder->list(
            $users->map(static fn (User $user): array => (new ScimUserResource($user))->resolve())->values()->all(),
            $total,
            $startIndex,
        );
    }

    /**
     * @throws ScimException
     */
    public function show(Request $request, string $user): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        return $this->responder->resource((new ScimUserResource($this->userOrFail($tenantId, $user)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        $created = $this->createUser->execute($tenantId, $this->mapper->fromResource($this->scimBody($request)));

        return $this->responder->resource((new ScimUserResource($this->userOrFail($tenantId, (string) $created->getKey())))->resolve(), 201);
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function replace(Request $request, string $user): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);
        $target = $this->userOrFail($tenantId, $user);

        $this->updateUser->execute($target, $this->mapper->fromResource($this->scimBody($request)));

        return $this->responder->resource((new ScimUserResource($this->userOrFail($tenantId, $user)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function update(Request $request, string $user): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);
        $target = $this->userOrFail($tenantId, $user);

        $this->updateUser->execute($target, $this->mapper->fromPatch($this->patches->parse($this->scimBody($request))));

        return $this->responder->resource((new ScimUserResource($this->userOrFail($tenantId, $user)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function destroy(Request $request, string $user): Response
    {
        $tenantId = $this->scimTenantId($request);

        $this->deleteUser->execute($this->userOrFail($tenantId, $user));

        return $this->responder->noContent();
    }

    private function userOrFail(string $tenantId, string $userId): User
    {
        return $this->users->find($tenantId, $userId) ?? throw ScimException::notFound();
    }
}
