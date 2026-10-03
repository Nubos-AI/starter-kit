<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Contracts\Modules\OutboundGuardInterface;
use App\DTOs\Webhooks\WebhookBasicAuth;
use App\Enums\Notifications\SuppressedChannel;
use App\Exceptions\Modules\OutboundBlockedException;
use App\Exceptions\Webhooks\SsrfBlockedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WebhookEgressClient
{
    private int $timeoutSeconds = 10;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
        private readonly OutboundGuardInterface $egressGuard,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SsrfBlockedException
     * @throws OutboundBlockedException
     * @throws ConnectionException
     */
    public function post(string $url, string $rawBody, array $headers, ?WebhookBasicAuth $basicAuth = null): Response
    {
        $this->assertHttps($url);

        $this->assertOutboundAllowed($url, $rawBody);

        $pinnedIp = $this->ssrfGuard->assertWebhookTargetAllowed($url);

        $request = Http::withoutRedirecting()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->ssrfGuard->resolveEntryFor($url, $pinnedIp)]]])
            ->withBody($rawBody, 'application/json');

        if ($basicAuth instanceof WebhookBasicAuth) {
            $request = $request->withBasicAuth($basicAuth->username, $basicAuth->password);
        }

        return $request->post($url);
    }

    /**
     * @throws OutboundBlockedException
     */
    private function assertOutboundAllowed(string $url, string $rawBody): void
    {
        $isSuppressed = $this->egressGuard->suppressIfBlocked(
            SuppressedChannel::Webhook,
            __('i18n.backend.support.webhooks.webhook_egress_client.an_installed_module_blocks_outbound_delivery_outbound_webhooks_are'),
            $url,
            null,
            ['byte_length' => strlen($rawBody)],
        );

        if (!$isSuppressed) {
            return;
        }

        throw $this->egressGuard->exceptionFor($url);
    }

    private function assertHttps(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.webhook_egress_client.only_https_is_allowed_for_webhook_destinations'), $url);
        }
    }
}
