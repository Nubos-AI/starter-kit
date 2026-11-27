<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Support\Webhooks\SsrfGuard;
use CurlHandle;
use Illuminate\Support\Facades\Log;

class PushEndpointConnectionGuard
{
    public static function allowsConnection(CurlHandle $handle, string $connectedIp, string $localIp, int $connectedPort, int $localPort): int
    {
        $literal = str_contains($connectedIp, ':') ? "[{$connectedIp}]" : $connectedIp;

        try {
            (new SsrfGuard)->assertWebhookTargetAllowed("https://{$literal}/");
        } catch (SsrfBlockedException) {
            Log::warning('Web push delivery aborted because the push endpoint connected to a private or reserved address', [
                'connected_ip' => $connectedIp,
                'endpoint_host' => parse_url((string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST),
            ]);

            return CURL_PREREQFUNC_ABORT;
        }

        return CURL_PREREQFUNC_OK;
    }
}
