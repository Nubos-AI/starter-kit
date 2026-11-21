<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Scim\CreateScimGroupAction;
use App\Actions\Scim\DeleteScimGroupAction;
use App\Actions\Scim\UpdateScimGroupAction;
use App\Exceptions\Scim\ScimException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Scim\ScimGroupResource;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Scim\ScimFilterParser;
use App\Support\Scim\ScimGroupDirectory;
use App\Support\Scim\ScimGroupMapper;
use App\Support\Scim\ScimPatchParser;
use App\Support\Scim\ScimResponder;
use App\Support\Tenancy\TenantContext;
use App\Traits\Scim\ResolvesScimTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ScimGroupsController extends Controller
{
    use ResolvesScimTenant;

    public function __construct(
        private readonly ScimGroupDirectory $groups,
        private readonly ScimFilterParser $filters,
        private readonly ScimPatchParser $patches,
        private readonly ScimGroupMapper $mapper,
        private readonly ScimResponder $responder,
        private readonly CreateScimGroupAction $createGroup,
        private readonly UpdateScimGroupAction $updateGroup,
        private readonly DeleteScimGroupAction $deleteGroup,
    ) {}

    /**
     * @throws ScimException
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);
        $filter = $this->filters->parse($request->query('filter') === null ? null : (string) $request->query('filter'), ScimGroupDirectory::$filterableAttributes);
        [$startIndex, $limit] = $this->scimPage($request);

        [$total, $teams] = $this->groups->page($tenantId, $filter, $startIndex - 1, $limit);

        return $this->responder->list(
            $teams->map(static fn (Team $team): array => (new ScimGroupResource($team))->resolve())->values()->all(),
            $total,
            $startIndex,
        );
    }

    /**
     * @throws ScimException
     */
    public function show(Request $request, string $group): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        return $this->responder->resource((new ScimGroupResource($this->groupOrFail($tenantId, $group)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        $team = $this->createGroup->execute($this->tenant(), $this->scimToken($request), $this->mapper->fromResource($this->scimBody($request)));

        return $this->responder->resource((new ScimGroupResource($this->groupOrFail($tenantId, (string) $team->getKey())))->resolve(), 201);
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function replace(Request $request, string $group): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        $this->updateGroup->execute($this->tenant(), $this->scimToken($request), $this->groupOrFail($tenantId, $group), $this->mapper->fromResource($this->scimBody($request)));

        return $this->responder->resource((new ScimGroupResource($this->groupOrFail($tenantId, $group)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function update(Request $request, string $group): JsonResponse
    {
        $tenantId = $this->scimTenantId($request);

        $this->updateGroup->execute($this->tenant(), $this->scimToken($request), $this->groupOrFail($tenantId, $group), $this->mapper->fromPatch($this->patches->parse($this->scimBody($request))));

        return $this->responder->resource((new ScimGroupResource($this->groupOrFail($tenantId, $group)))->resolve());
    }

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function destroy(Request $request, string $group): Response
    {
        $tenantId = $this->scimTenantId($request);

        $this->deleteGroup->execute($this->tenant(), $this->scimToken($request), $this->groupOrFail($tenantId, $group));

        return $this->responder->noContent();
    }

    private function groupOrFail(string $tenantId, string $teamId): Team
    {
        return $this->groups->find($tenantId, $teamId) ?? throw ScimException::notFound();
    }

    private function tenant(): Tenant
    {
        return TenantContext::current() ?? throw ScimException::unauthorized();
    }
}
