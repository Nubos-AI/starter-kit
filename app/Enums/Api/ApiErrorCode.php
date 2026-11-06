<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum ApiErrorCode: string
{
    case NotFound = 'not_found';

    case Unauthenticated = 'unauthenticated';

    case Forbidden = 'forbidden';

    case ValidationFailed = 'validation_failed';

    case RateLimited = 'rate_limited';

    case StaleRecord = 'stale_record';

    case IdempotencyConcurrent = 'idempotency_concurrent';

    case IdempotencyKeyReuse = 'idempotency_key_reuse';

    case ServerError = 'server_error';

    case ServiceUnavailable = 'service_unavailable';

    public function title(): string
    {
        return match ($this) {
            self::NotFound => __('i18n.backend.enums.api.api_error_code.not_found'),
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
