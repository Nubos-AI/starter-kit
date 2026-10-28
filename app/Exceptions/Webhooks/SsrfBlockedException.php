<?php

declare(strict_types=1);

namespace App\Exceptions\Webhooks;

use RuntimeException;

class SsrfBlockedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $url,
        public readonly ?string $ip = null,
    ) {
        $target = $ip !== null ? __('i18n.backend.exceptions.webhooks.ssrf_blocked_exception.resolved_ip_address', ['value1' => $ip]) : '';

        parent::__construct(
            __('i18n.backend.exceptions.webhooks.ssrf_blocked_exception.the_request_to_was_rejected', ['value1' => $url, 'value2' => $reason, 'value3' => $target]),
        );
    }
}
