<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\Api\ApiErrorCode;
use Illuminate\Http\JsonResponse;

class JsonApiErrorBag
{
    /**
     * @param  array<string, string>|null  $source
     * @param  array<string, mixed>|null  $meta
     */
    public static function single(
        int $status,
        ApiErrorCode $code,
        string $detail,
        ?array $source = null,
        ?array $meta = null,
    ): JsonResponse {
        $member = [
            'status' => (string) $status,
            'code' => $code->value,
            'title' => $code->title(),
            'detail' => $detail,
        ];

        if ($source !== null) {
            $member['source'] = $source;
        }

        $body = ['errors' => [$member]];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * @param  array<string, list<string>>  $fieldMessages
     */
    public static function perField(int $status, ApiErrorCode $code, array $fieldMessages): JsonResponse
    {
        $errors = [];

        foreach ($fieldMessages as $field => $messages) {
            $errors[] = [
                'status' => (string) $status,
                'code' => $code->value,
                'title' => $code->title(),
                'detail' => implode(' ', $messages),
                'source' => ['pointer' => "/data/attributes/{$field}"],
            ];
        }

        return new JsonResponse(['errors' => $errors], $status);
    }
}
