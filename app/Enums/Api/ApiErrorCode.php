<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum ApiErrorCode: string
{
    case BadRequest = 'bad_request';

    case NotFound = 'not_found';

    case MethodNotAllowed = 'method_not_allowed';

    case Conflict = 'conflict';

    case PayloadTooLarge = 'payload_too_large';

    case ClientError = 'client_error';

    case Unauthenticated = 'unauthenticated';

    case Forbidden = 'forbidden';

    case ValidationFailed = 'validation_failed';

    case RateLimited = 'rate_limited';

    case StaleRecord = 'stale_record';

    case IdempotencyConcurrent = 'idempotency_concurrent';

    case IdempotencyKeyReuse = 'idempotency_key_reuse';

    case ServerError = 'server_error';

    case ServiceUnavailable = 'service_unavailable';

    public static function forHttpStatus(int $status): self
    {
        return match (true) {
            $status === 400 => self::BadRequest,
            $status === 401 => self::Unauthenticated,
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 405 => self::MethodNotAllowed,
            $status === 409 => self::Conflict,
            $status === 413 => self::PayloadTooLarge,
            $status === 429 => self::RateLimited,
            $status === 503 => self::ServiceUnavailable,
            $status >= 400 && $status < 500 => self::ClientError,
            default => self::ServerError,
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::BadRequest => __('i18n.backend.enums.api.api_error_code.the_request_is_malformed'),
            self::MethodNotAllowed => __('i18n.backend.enums.api.api_error_code.this_method_is_not_allowed_for_this_resource'),
            self::Conflict => __('i18n.backend.enums.api.api_error_code.the_request_conflicts_with_the_current_state_of_the_resource'),
            self::PayloadTooLarge => __('i18n.backend.enums.api.api_error_code.the_request_is_too_large'),
            self::ServiceUnavailable => __('i18n.backend.bootstrap.app.the_service_is_temporarily_unavailable_please_try_again_later'),
            self::NotFound => __('i18n.backend.bootstrap.app.the_requested_resource_could_not_be_found'),
            self::Unauthenticated => __('i18n.backend.bootstrap.app.authentication_is_required_to_access_this_resource'),
            self::Forbidden => __('i18n.backend.bootstrap.app.you_may_not_perform_this_action'),
            self::RateLimited => __('i18n.backend.bootstrap.app.too_many_requests_please_slow_down_and_retry_later'),
            self::ServerError => __('i18n.backend.bootstrap.app.an_unexpected_error_occurred_please_try_again_later_2'),
            default => __('i18n.backend.enums.api.api_error_code.the_request_could_not_be_processed'),
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::BadRequest => __('i18n.backend.enums.api.api_error_code.bad_request'),
            self::NotFound => __('i18n.backend.enums.api.api_error_code.not_found'),
            self::MethodNotAllowed => __('i18n.backend.enums.api.api_error_code.method_not_allowed'),
            self::Conflict => __('i18n.backend.enums.api.api_error_code.conflict'),
            self::PayloadTooLarge => __('i18n.backend.enums.api.api_error_code.payload_too_large'),
            self::ClientError => __('i18n.backend.enums.api.api_error_code.client_error'),
            self::Unauthenticated => __('i18n.backend.enums.api.api_error_code.unauthenticated'),
            self::Forbidden => __('i18n.backend.enums.api.api_error_code.forbidden'),
            self::ValidationFailed => __('i18n.backend.enums.api.api_error_code.validation_failed'),
            self::RateLimited => __('i18n.backend.enums.api.api_error_code.too_many_requests'),
            self::StaleRecord => __('i18n.backend.enums.api.api_error_code.conflict'),
            self::IdempotencyConcurrent => __('i18n.backend.enums.api.api_error_code.conflict'),
            self::IdempotencyKeyReuse => __('i18n.backend.enums.api.api_error_code.idempotency_key_reuse'),
            self::ServerError => __('i18n.backend.enums.api.api_error_code.internal_server_error'),
            self::ServiceUnavailable => __('i18n.backend.enums.api.api_error_code.service_unavailable'),
        };
    }
}
