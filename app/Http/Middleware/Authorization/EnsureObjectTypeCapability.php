<?php

declare(strict_types=1);

namespace App\Http\Middleware\Authorization;

use App\Enums\Engine\ObjectTypeCapability;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordRouteResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureObjectTypeCapability
{
    public function __construct(
        private readonly ObjectTypeCapabilityGuard $guard,
        private readonly RecordRouteResolver $records,
        private readonly ObjectTypeRegistry $types,
    ) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $this->guard->assertSupports(
            $this->objectType($request),
            ObjectTypeCapability::from($capability),
        );

        return $next($request);
    }

    private function objectType(Request $request): ObjectType
    {
        foreach ($request->route()?->parameters() ?? [] as $name => $parameter) {
            if ($parameter instanceof ObjectType) {
                return $parameter;
            }

            if ($parameter instanceof CustomRecord) {
                return $parameter->objectType;
            }

            if ($name === 'record' && is_string($parameter)) {
                return $this->records->resolve($parameter, true)->objectType;
            }

            if ($name === 'objectType' && is_string($parameter)) {
                return $this->unboundObjectType($parameter);
            }
        }

        throw new LogicException(__('i18n.backend.http.middleware.authorization.ensure_object_type_capability.no_route_parameter_of_this_request_resolves_to_an'));
    }

    private function unboundObjectType(string $identifier): ObjectType
    {
        $objectType = $this->types->find($identifier);

        if ($objectType instanceof ObjectType) {
            return $objectType;
        }

        try {
            return $this->types->bySlug($identifier);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }
}
