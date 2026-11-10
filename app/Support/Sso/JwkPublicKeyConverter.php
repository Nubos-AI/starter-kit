<?php

declare(strict_types=1);

namespace App\Support\Sso;

class JwkPublicKeyConverter
{
    private string $rsaAlgorithmIdentifier = '300d06092a864886f70d0101010500';

    private string $p256AlgorithmIdentifier = '301306072a8648ce3d020106082a8648ce3d030107';

    private int $p256CoordinateLength = 32;

    /**
     * @param  array<string, mixed>  $jwk
     */
    public function toPem(array $jwk): ?string
    {
        $der = match ($jwk['kty'] ?? null) {
            'RSA' => $this->rsaDer($jwk),
            'EC' => $this->p256Der($jwk),
            default => null,
        };

        if ($der === null) {
            return null;
        }

        $body = chunk_split(base64_encode($der), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$body}-----END PUBLIC KEY-----\n";
    }

    public function rawSignatureToDer(string $signature): ?string
    {
        if (strlen($signature) !== 2 * $this->p256CoordinateLength) {
            return null;
        }

        $r = $this->integer(substr($signature, 0, $this->p256CoordinateLength));
        $s = $this->integer(substr($signature, $this->p256CoordinateLength));

        return $this->sequence($r.$s);
    }

    public function decodeBase64Url(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function rsaDer(array $jwk): ?string
    {
        $modulus = $this->decodeBase64Url((string) ($jwk['n'] ?? ''));
        $exponent = $this->decodeBase64Url((string) ($jwk['e'] ?? ''));

        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            return null;
        }

        $publicKey = $this->sequence($this->integer($modulus).$this->integer($exponent));

        return $this->sequence((string) hex2bin($this->rsaAlgorithmIdentifier).$this->bitString($publicKey));
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function p256Der(array $jwk): ?string
    {
        if (($jwk['crv'] ?? null) !== 'P-256') {
            return null;
        }

        $x = $this->decodeBase64Url((string) ($jwk['x'] ?? ''));
        $y = $this->decodeBase64Url((string) ($jwk['y'] ?? ''));

        if ($x === null || $y === null || strlen($x) !== $this->p256CoordinateLength || strlen($y) !== $this->p256CoordinateLength) {
            return null;
        }

        return $this->sequence((string) hex2bin($this->p256AlgorithmIdentifier).$this->bitString("\x04{$x}{$y}"));
    }

    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00{$bytes}";
        }

        return "\x02".$this->length(strlen($bytes)).$bytes;
    }

    private function bitString(string $bytes): string
    {
        return "\x03".$this->length(strlen($bytes) + 1)."\x00{$bytes}";
    }

    private function sequence(string $bytes): string
    {
        return "\x30".$this->length(strlen($bytes)).$bytes;
    }

    private function length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return pack('C', 0x80 | strlen($encoded)).$encoded;
    }
}
