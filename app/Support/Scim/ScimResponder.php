<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\Enums\Scim\ScimErrorType;
use App\Enums\Scim\ScimSchema;
use App\Exceptions\Scim\ScimException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ScimResponder
{
    private string $mediaType = 'application/scim+json; charset=utf-8';

    /**
     * @param  array<string, mixed>  $resource
     */
    public function resource(array $resource, int $status = 200): JsonResponse
    {
        $response = $this->json($resource, $status);
        $version = Arr::get($resource, 'meta.version');
        $location = Arr::get($resource, 'meta.location');

        if (is_string($version)) {
            $response->headers->set('ETag', $version);
        }

        if ($status === 201 && is_string($location)) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }

    /**
     * @param  array<int, array<mixed>>  $resources
     */
    public function list(array $resources, int $totalResults, int $startIndex): JsonResponse
    {
        return $this->json([
            'schemas' => [ScimSchema::ListResponse->value],
            'totalResults' => $totalResults,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => array_values($resources),
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function document(array $document): JsonResponse
    {
        return $this->json($document);
    }

    public function noContent(): Response
    {
        return new Response('', 204);
    }

    public function failure(Throwable $throwable): JsonResponse
    {
        return match (true) {
            $throwable instanceof ScimException => $this->error($throwable->status, $throwable->scimType, $throwable->getMessage()),
            $throwable instanceof ValidationException => $this->error(400, ScimErrorType::InvalidValue, (string) Arr::first(Arr::flatten($throwable->errors()))),
            $throwable instanceof AuthenticationException => $this->error(401, null, ScimException::unauthorized()->getMessage()),
            $throwable instanceof ModelNotFoundException => $this->error(404, null, ScimException::notFound()->getMessage()),
            $throwable instanceof ThrottleRequestsException => $this->error(429, null, __('i18n.backend.bootstrap.app.too_many_requests_please_slow_down_and_retry_later'))->withHeaders($throwable->getHeaders()),
            $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() === 404 => $this->error(404, null, ScimException::notFound()->getMessage()),
            $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500 => $this->error($throwable->getStatusCode(), null, ScimException::invalidSyntax()->getMessage()),
            default => $this->unexpected($throwable),
        };
    }

    private function unexpected(Throwable $throwable): JsonResponse
    {
        Log::error('A scim request failed unexpectedly.', ['exception' => $throwable::class]);

        return $this->error(500, null, __('i18n.backend.bootstrap.app.an_unexpected_error_occurred_please_try_again_later_2'));
    }

    private function error(int $status, ?ScimErrorType $scimType, string $detail): JsonResponse
    {
        $body = ['schemas' => [ScimSchema::Error->value], 'status' => (string) $status];

        if ($scimType !== null) {
            $body['scimType'] = $scimType->value;
        }

        return $this->json([...$body, 'detail' => $detail], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Content-Type' => $this->mediaType], JSON_UNESCAPED_SLASHES);
    }
}
