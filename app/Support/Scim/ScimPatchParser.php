<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimPatchOperationData;
use App\Enums\Scim\ScimPatchOperation;
use App\Enums\Scim\ScimSchema;
use App\Exceptions\Scim\ScimException;
use Illuminate\Support\Str;

class ScimPatchParser
{
    /**
     * @param  array<string, mixed>  $body
     * @return list<ScimPatchOperationData>
     *
     * @throws ScimException
     */
    public function parse(array $body): array
    {
        $schemas = $body['schemas'] ?? null;
        $operations = $body['Operations'] ?? null;

        if (!is_array($schemas) || !in_array(ScimSchema::PatchOp->value, $schemas, true) || !is_array($operations) || $operations === []) {
            throw ScimException::invalidSyntax();
        }

        return array_values(array_map(fn (mixed $operation): ScimPatchOperationData => $this->operation($operation), $operations));
    }

    private function operation(mixed $operation): ScimPatchOperationData
    {
        if (!is_array($operation)) {
            throw ScimException::invalidSyntax();
        }

        $kind = ScimPatchOperation::tryFrom(Str::lower((string) ($operation['op'] ?? '')));
        $path = is_string($operation['path'] ?? null) && trim($operation['path']) !== '' ? trim($operation['path']) : null;

        if ($kind === null || ($kind === ScimPatchOperation::Remove && $path === null)) {
            throw ScimException::invalidSyntax();
        }

        return new ScimPatchOperationData($kind, $path, $operation['value'] ?? null);
    }
}
