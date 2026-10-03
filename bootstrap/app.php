<?php

declare(strict_types=1);

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\StaleRecordException;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\RejectTransientToken;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Http\Middleware\Api\ThrottleApiRequests;
use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Http\Middleware\Authorization\EnsureObjectTypeCapability;
use App\Http\Middleware\Authorization\EnsurePermission;
use App\Http\Middleware\EnforceMaintenanceLock;
use App\Http\Middleware\EnforceQueryBudget;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTeamContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\RunModuleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Support\Api\JsonApiErrorBag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Support\Header;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->trimStrings(except: ['q', 'search', 'selection.search', 'scope.search', 'typedTenantName']);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ResolveTenantContext::class,
            EnforceMaintenanceLock::class,
            ResolveTeamContext::class,
            EnforceRecordAccessRules::class,
        ]);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenantContext::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RunModuleMiddleware::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTeamContext::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnforceRecordAccessRules::class,
        );

        $middleware->group('api', [
            SetLocale::class,
            'auth:sanctum',
            ThrottleApiRequests::class.':api',
            RejectTransientToken::class,
            ResolveApiContext::class,
            ResolveTenantContext::class,
            EnforceMaintenanceLock::class,
            EnforceRecordAccessRules::class,
        ]);

        $middleware->alias([
            'idempotency' => EnforceIdempotencyKey::class,
            'capability' => EnsureObjectTypeCapability::class,
            'permission' => EnsurePermission::class,
            'query-budget' => EnforceQueryBudget::class,
            'live-tenant' => RunModuleMiddleware::class.':primary',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $webErrorMessage = static fn (int $status): string => match ($status) {
            403 => __('i18n.backend.bootstrap.app.you_may_not_perform_this_action'),
            404 => __('i18n.backend.bootstrap.app.the_requested_page_was_not_found'),
            419 => __('i18n.backend.bootstrap.app.your_session_has_expired_please_reload_the_page'),
            429 => __('i18n.backend.bootstrap.app.too_many_requests_please_wait_a_moment'),
            503 => __('i18n.backend.bootstrap.app.the_service_is_temporarily_unavailable_please_try_again_later'),
            default => __('i18n.backend.bootstrap.app.an_unexpected_error_occurred_please_try_again_later'),
        };

        $webErrorPayload = static function (JsonResponse $response, Throwable $throwable, string $fallback): ?array {
            $payload = $response->getData(true);
            $debugKeys = ['exception', 'file', 'line', 'trace'];
            $namesAModel = $throwable instanceof ModelNotFoundException
                || $throwable->getPrevious() instanceof ModelNotFoundException;

            if (!is_array($payload) || (!$namesAModel && array_intersect(array_keys($payload), $debugKeys) === [])) {
                return null;
            }

            $sanitized = Arr::except($payload, $debugKeys);

            $keepsItsMessage = !$namesAModel
                && $throwable instanceof HttpExceptionInterface
                && is_string($sanitized['message'] ?? null)
                && $sanitized['message'] !== '';

            if (!$keepsItsMessage) {
                $sanitized['message'] = $fallback;
            }

            return $sanitized;
        };

        $cancelledQueryStatus = 503;

        $cancelledQuerySqlState = '57014';

        $queryWasCancelled = static function (Throwable $throwable) use ($cancelledQuerySqlState): bool {
            if (!$throwable instanceof QueryException) {
                return false;
            }

            $previous = $throwable->getPrevious();
            $sqlState = $previous instanceof PDOException ? ($previous->errorInfo[0] ?? null) : null;

            return $sqlState === $cancelledQuerySqlState
                || (string) $throwable->getCode() === $cancelledQuerySqlState;
        };

        $webRequestComesFromTheUi = static fn (Request $request): bool => $request->hasHeader(Header::INERTIA)
            || $request->ajax()
            || $request->expectsJson();

        $exceptions->respond(function (Response $response, Throwable $throwable, Request $request) use ($webErrorMessage, $webErrorPayload, $webRequestComesFromTheUi, $queryWasCancelled, $cancelledQueryStatus): Response {
            if (!$request->is('api/*')) {
                if ($queryWasCancelled($throwable)) {
                    Log::warning('A record query was cancelled because it exceeded the statement timeout.', [
                        'route' => $request->path(),
                        'user_id' => $request->user()?->getAuthIdentifier(),
                        'tenant_id' => app()->bound('current_tenant') ? app('current_tenant')->getKey() : null,
                    ]);

                    $timeoutMessage = __('i18n.backend.bootstrap.app.the_query_took_too_long_please_narrow_your_filters');

                    if ($webRequestComesFromTheUi($request)) {
                        return new JsonResponse(['message' => $timeoutMessage], $cancelledQueryStatus);
                    }

                    return Inertia::render('errors/Error', ['status' => $cancelledQueryStatus])
                        ->toResponse($request)
                        ->setStatusCode($cancelledQueryStatus);
                }

                $status = $response->getStatusCode();

                if ($status < 400
                    || $throwable instanceof ValidationException
                    || $throwable instanceof AuthenticationException) {
                    return $response;
                }

                if ($response instanceof JsonResponse) {
                    $payload = $webErrorPayload($response, $throwable, $webErrorMessage($status));

                    return $payload === null ? $response : $response->setData($payload);
                }

                if (config('app.debug')
                    && !$throwable instanceof HttpExceptionInterface
                    && !$webRequestComesFromTheUi($request)) {
                    return $response;
                }

                return Inertia::render('errors/Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return match (true) {
                $throwable instanceof MissingAbilityException,
                $throwable->getPrevious() instanceof MissingAbilityException => JsonApiErrorBag::single(
                    403,
                    ApiErrorCode::Forbidden,
                    __('i18n.backend.bootstrap.app.the_provided_token_lacks_the_required_ability_for_this'),
                ),
                $throwable instanceof AuthorizationException,
                $throwable instanceof AccessDeniedHttpException,
                $throwable instanceof ModelNotFoundException,
                $throwable instanceof NotFoundHttpException => JsonApiErrorBag::single(
                    404,
                    ApiErrorCode::NotFound,
                    __('i18n.backend.bootstrap.app.the_requested_resource_could_not_be_found'),
                ),
                $throwable instanceof AuthenticationException => JsonApiErrorBag::single(
                    401,
                    ApiErrorCode::Unauthenticated,
                    __('i18n.backend.bootstrap.app.authentication_is_required_to_access_this_resource'),
                ),
                $throwable instanceof ValidationException => JsonApiErrorBag::perField(
                    422,
                    ApiErrorCode::ValidationFailed,
                    $throwable->errors(),
                ),
                $throwable instanceof ThrottleRequestsException => JsonApiErrorBag::single(
                    429,
                    ApiErrorCode::RateLimited,
                    __('i18n.backend.bootstrap.app.too_many_requests_please_slow_down_and_retry_later'),
                )->withHeaders($throwable->getHeaders()),
                $throwable instanceof StaleRecordException => JsonApiErrorBag::single(
                    409,
                    ApiErrorCode::StaleRecord,
                    $throwable->getMessage(),
                    null,
                    ['currentVersion' => $throwable->currentVersion()],
                ),
                $throwable instanceof TenantUnderMaintenanceException => JsonApiErrorBag::single(
                    503,
                    ApiErrorCode::ServiceUnavailable,
                    $throwable->getMessage(),
                ),
                default => JsonApiErrorBag::single(
                    500,
                    ApiErrorCode::ServerError,
                    __('i18n.backend.bootstrap.app.an_unexpected_error_occurred_please_try_again_later_2'),
                ),
            };
        });
    })->create();
