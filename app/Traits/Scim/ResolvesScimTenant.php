<?php

declare(strict_types=1);

namespace App\Traits\Scim;

use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use Illuminate\Http\Request;

trait ResolvesScimTenant
{
    /**
     * @throws ScimException
     */
    protected function scimTenantId(Request $request): string
    {
        return $this->scimToken($request)->tenant_id;
    }

    /**
     * @throws ScimException
     */
    protected function scimToken(Request $request): ScimToken
    {
        $token = $request->attributes->get('scim_token');

        if (!$token instanceof ScimToken) {
            throw ScimException::unauthorized();
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    protected function scimBody(Request $request): array
    {
        if (!$request->isJson()) {
            throw ScimException::invalidSyntax();
        }

        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        return $body;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function scimPage(Request $request): array
    {
        $startIndex = max(1, (int) $request->query('startIndex', '1'));
        $count = (int) $request->query('count', (string) config('sso.scim.default_page_size'));
        $limit = min(max(0, $count), (int) config('sso.scim.max_page_size'));

        return [$startIndex, $limit];
    }
}
