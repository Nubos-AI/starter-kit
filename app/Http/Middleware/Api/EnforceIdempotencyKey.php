<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Api\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Support\Api\JsonApiErrorBag;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Idempotency-Key');

        if ($header === null || $header === '') {
            return $next($request);
        }

        $key = trim($header);

        if ($key === '' || mb_strlen($key) > 255) {
            return JsonApiErrorBag::single(
                422,
                ApiErrorCode::ValidationFailed,
                __('i18n.backend.http.middleware.api.enforce_idempotency_key.the_idempotency_key_header_must_be_a_non_empty'),
                ['header' => 'Idempotency-Key'],
            );
        }

        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);
        $tenantId = $request->user()?->tenant_id;

        if (!$token instanceof PersonalAccessToken || $tenantId === null) {
            throw new AuthenticationException;
        }

        $tokenId = (int) $token->getKey();
        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $now = CarbonImmutable::now();
        $claimId = (string) Str::ulid();

        $claimed = DB::table('idempotency_keys')->insertOrIgnore([
            'id' => $claimId,
            'tenant_id' => $tenantId,
            'access_token_id' => $tokenId,
            'idempotency_key' => $key,
            'request_body_hash' => $hash,
            'status' => IdempotencyStatus::InProgress->value,
            'response_status' => null,
            'response_body' => null,
            'locked_at' => $now,
            'created_at' => $now,
        ]);

        if ($claimed === 1) {
            return $this->execute($request, $next, $claimId);
        }

        $row = IdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('access_token_id', $tokenId)
            ->where('idempotency_key', $key)
            ->first();

        if (!$row instanceof IdempotencyKey) {
            return $this->concurrent();
        }

        if ($row->request_body_hash !== $hash) {
            return JsonApiErrorBag::single(
                422,
                ApiErrorCode::IdempotencyKeyReuse,
                __('i18n.backend.http.middleware.api.enforce_idempotency_key.this_idempotency_key_was_already_used_with_a_different'),
                ['header' => 'Idempotency-Key'],
            );
        }

        if ($row->status === IdempotencyStatus::Completed) {
            return $this->replay($row);
        }

        $cutoff = $now->subSeconds((int) config('api.idempotency.lock_timeout_seconds'));

        if ($row->locked_at->greaterThan($cutoff)) {
            return $this->concurrent();
        }

        $reclaimed = IdempotencyKey::query()
            ->whereKey($row->getKey())
            ->where('status', IdempotencyStatus::InProgress->value)
            ->where('locked_at', '<=', $cutoff)
            ->update(['locked_at' => $now]);

        if ($reclaimed !== 1) {
            return $this->concurrent();
        }

        return $this->execute($request, $next, (string) $row->getKey());
    }

    private function execute(Request $request, Closure $next, string $claimId): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            IdempotencyKey::query()->whereKey($claimId)->delete();

            return $response;
        }

        IdempotencyKey::query()->whereKey($claimId)->update([
            'status' => IdempotencyStatus::Completed->value,
            'response_status' => $response->getStatusCode(),
            'response_body' => $this->encodeBody($response),
        ]);

        return $response;
    }

    private function replay(IdempotencyKey $row): Response
    {
        $status = $row->response_status ?? 200;

        if ($row->response_body === null) {
            return new Response('', $status);
        }

        return new JsonResponse($row->response_body, $status);
    }

    private function concurrent(): JsonResponse
    {
        return JsonApiErrorBag::single(
            409,
            ApiErrorCode::IdempotencyConcurrent,
            __('i18n.backend.http.middleware.api.enforce_idempotency_key.a_request_with_this_idempotency_key_is_already_in'),
        );
    }

    private function encodeBody(Response $response): ?string
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        return is_array(json_decode($content, true)) ? $content : null;
    }
}
