<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Abstracts\Controller;
use App\Support\Scim\ScimResponder;
use App\Support\Scim\ScimServiceProviderDocuments;
use Illuminate\Http\JsonResponse;

class ScimDiscoveryController extends Controller
{
    public function __construct(
        private readonly ScimServiceProviderDocuments $documents,
        private readonly ScimResponder $responder,
    ) {}

    public function serviceProviderConfig(): JsonResponse
    {
        return $this->responder->document($this->documents->serviceProviderConfig());
    }

    public function resourceTypes(): JsonResponse
    {
        $types = $this->documents->resourceTypes();

        return $this->responder->list($types, count($types), 1);
    }

    public function schemas(): JsonResponse
    {
        $schemas = $this->documents->schemas();

        return $this->responder->list($schemas, count($schemas), 1);
    }
}
