<?php

declare(strict_types=1);

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(Throwable, string):Response */
    $this->rendered = static function (Throwable $throwable, string $uri = '/api/v1/companies'): Response {
        $request = Request::create($uri, 'GET');
        $request->headers->set('Accept', 'application/json');

        return app(ExceptionHandler::class)->render($request, $throwable);
    };

    /** @var callable(Response):array<string, mixed> */
    $this->payloadOf = static function (Response $response): array {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded;
    };
});

it('answers every api failure with an errors envelope whose status member matches the http status', function (Throwable $throwable, int $status, ApiErrorCode $code): void {
    $response = ($this->rendered)($throwable);
    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe($status)
        ->and($payload['errors'][0]['status'])->toBe((string) $status)
        ->and($payload['errors'][0]['code'])->toBe($code->value)
        ->and($payload['errors'][0])->toHaveKeys(['status', 'code', 'title', 'detail']);
})->with([
    'missing ability' => [fn (): Throwable => new MissingAbilityException(['records:read']), 403, ApiErrorCode::Forbidden],
    'denied policy' => [fn (): Throwable => new AuthorizationException, 404, ApiErrorCode::NotFound],
    'missing model' => [fn (): Throwable => (new ModelNotFoundException)->setModel(CustomRecord::class, ['x']), 404, ApiErrorCode::NotFound],
    'missing route' => [fn (): Throwable => new NotFoundHttpException, 404, ApiErrorCode::NotFound],
    'unauthenticated' => [fn (): Throwable => new AuthenticationException, 401, ApiErrorCode::Unauthenticated],
    'throttled' => [fn (): Throwable => new ThrottleRequestsException('slow down', null, ['Retry-After' => 42]), 429, ApiErrorCode::RateLimited],
    'maintenance' => [fn (): Throwable => TenantUnderMaintenanceException::writeRefused('01JTENANT0000000000000000'), 503, ApiErrorCode::ServiceUnavailable],
    'unexpected' => [fn (): Throwable => new RuntimeException('internal detail'), 500, ApiErrorCode::ServerError],
]);

it('reshapes a denied policy into the same body as a missing route so existence is never disclosed', function (): void {
    $denied = ($this->payloadOf)(($this->rendered)(new AuthorizationException('you may not see this record')));
    $missing = ($this->payloadOf)(($this->rendered)(new NotFoundHttpException));

    expect($denied)->toBe($missing);
});

it('never leaks an internal failure detail into the api body', function (): void {
    $payload = ($this->payloadOf)(($this->rendered)(new RuntimeException('connection string leaked')));

    expect(json_encode($payload))->not->toContain('connection string leaked')
        ->and($payload['errors'][0])->not->toHaveKey('exception')
        ->and($payload['errors'][0])->not->toHaveKey('trace');
});

it('turns a validation failure into one member per field carrying a source pointer', function (): void {
    $exception = ValidationException::withMessages([
        'data.attributes.name' => ['Der Name fehlt.'],
        'data.attributes.version' => ['Die Version fehlt.'],
    ]);

    $payload = ($this->payloadOf)(($this->rendered)($exception));

    expect($payload['errors'])->toHaveCount(2)
        ->and($payload['errors'][0]['source'])->toBe(['pointer' => '/data/attributes/data.attributes.name'])
        ->and($payload['errors'][1]['source'])->toBe(['pointer' => '/data/attributes/data.attributes.version']);
});

it('turns a stale record into a conflict that carries the version in meta and drops the legacy body', function (): void {
    $response = ($this->rendered)(new StaleRecordException);
    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe(409)
        ->and($payload['errors'][0]['code'])->toBe(ApiErrorCode::StaleRecord->value)
        ->and($payload)->toHaveKey('meta')
        ->and($payload['meta'])->toHaveKey('currentVersion')
        ->and($payload)->not->toHaveKey('data')
        ->and($payload)->not->toHaveKey('message');
});

it('keeps the retry after header on a throttled api response', function (): void {
    $response = ($this->rendered)(new ThrottleRequestsException('slow down', null, ['Retry-After' => 42]));

    expect($response->headers->get('Retry-After'))->toBe('42');
});

it('leaves a failure outside the api path in its own rendering', function (): void {
    $response = ($this->rendered)(new NotFoundHttpException, '/engine/records');
    $payload = ($this->payloadOf)($response);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($payload)->not->toHaveKey('errors');
});

it('keeps the real status of any other http failure on the api instead of reporting a server error', function (Throwable $throwable, int $status, ApiErrorCode $code): void {
    $response = ($this->rendered)($throwable);
    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe($status)
        ->and($payload['errors'][0]['status'])->toBe((string) $status)
        ->and($payload['errors'][0]['code'])->toBe($code->value)
        ->and($payload['errors'][0]['title'])->toBe($code->title())
        ->and($payload['errors'][0]['title'])->not->toStartWith('i18n.')
        ->and($payload['errors'][0]['detail'])->not->toStartWith('i18n.');
})->with([
    'malformed request' => [fn (): Throwable => new BadRequestHttpException('bad json'), 400, ApiErrorCode::BadRequest],
    'wrong method' => [fn (): Throwable => new MethodNotAllowedHttpException(['GET', 'HEAD'], 'The POST method is not supported'), 405, ApiErrorCode::MethodNotAllowed],
    'conflict' => [fn (): Throwable => new ConflictHttpException('internal conflict'), 409, ApiErrorCode::Conflict],
    'too large' => [fn (): Throwable => new HttpException(413, 'too large'), 413, ApiErrorCode::PayloadTooLarge],
    'other client failure' => [fn (): Throwable => new HttpException(418, 'teapot'), 418, ApiErrorCode::ClientError],
    'aborted as unauthenticated' => [fn (): Throwable => new HttpException(401, 'no session'), 401, ApiErrorCode::Unauthenticated],
    'aborted as forbidden' => [fn (): Throwable => new HttpException(403, 'not yours'), 403, ApiErrorCode::Forbidden],
    'unavailable' => [fn (): Throwable => new ServiceUnavailableHttpException(30, 'down'), 503, ApiErrorCode::ServiceUnavailable],
]);

it('keeps the headers of an http failure such as the allowed methods and never leaks its internal message', function (): void {
    $response = ($this->rendered)(new MethodNotAllowedHttpException(['GET', 'HEAD'], 'The POST method is not supported for route api/v1/companies'));

    expect($response->headers->get('Allow'))->toBe('GET, HEAD')
        ->and((string) $response->getContent())->not->toContain('not supported for route');
});
