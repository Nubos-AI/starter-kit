<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use DateTimeInterface;
use SensitiveParameter;

class WebhookSignature
{
    private string $schemeVersion = 'v1';

    private string $timestampKey = 't';

    private string $algorithm = 'sha256';

    public function sign(string $rawBody, #[SensitiveParameter] string $secret, int $timestamp): string
    {
        $signature = hash_hmac($this->algorithm, $this->signedPayload($rawBody, $timestamp), $secret);

        return $this->timestampKey.'='.$timestamp.','.$this->schemeVersion.'='.$signature;
    }

    public function verify(
        string $rawBody,
        #[SensitiveParameter] string $secret,
        string $header,
        DateTimeInterface $now,
        int $toleranceSeconds = 300,
    ): bool {
        ['t' => $timestamp, 'v1' => $candidates] = $this->parseHeader($header);

        if ($timestamp === null || $candidates === []) {
            return false;
        }

        if (abs($now->getTimestamp() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac($this->algorithm, $this->signedPayload($rawBody, $timestamp), $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function signedPayload(string $rawBody, int $timestamp): string
    {
        return $timestamp.'.'.$rawBody;
    }

    /**
     * @return array{t: ?int, v1: list<string>}
     */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $header) as $item) {
            $parts = explode('=', $item, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;

            if ($key === $this->timestampKey && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === $this->schemeVersion) {
                $candidates[] = $value;
            }
        }

        return ['t' => $timestamp, 'v1' => $candidates];
    }
}
