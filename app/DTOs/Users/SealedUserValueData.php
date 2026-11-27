<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use App\Contracts\Temporal\ProcessInputFingerprintInterface;
use App\Support\Temporal\ProcessSeal;
use Illuminate\Contracts\Encryption\Encrypter;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class SealedUserValueData implements ProcessInputFingerprintInterface, TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $sealedValue,
        private ?string $valueDigest = null,
    ) {}

    public static function sealed(Encrypter $encrypter, string $tenantId, string $userId, string $value): self
    {
        return new self($tenantId, $userId, ProcessSeal::seal($encrypter, self::binding($tenantId, $userId), $value), ProcessSeal::digest($value));
    }

    public static function empty(string $tenantId, string $userId): self
    {
        return new self($tenantId, $userId, '');
    }

    public function hasValue(): bool
    {
        return $this->sealedValue !== '';
    }

    public function unsealedValue(Encrypter $encrypter): string
    {
        return ProcessSeal::unseal($encrypter, self::binding($this->tenantId, $this->userId), $this->sealedValue);
    }

    public function processFingerprint(): string
    {
        return "{$this->tenantId}|{$this->userId}|".($this->valueDigest ?? $this->sealedValue);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            userId: (string) $data['user_id'],
            sealedValue: (string) $data['sealed_value'],
        );
    }

    /**
     * @return array{tenant_id: string, user_id: string, sealed_value: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'sealed_value' => $this->sealedValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return self::fromArray($payload);
    }

    /**
     * @return array{tenant_id: string, user_id: string}
     */
    private static function binding(string $tenantId, string $userId): array
    {
        return ['tenant_id' => $tenantId, 'user_id' => $userId];
    }
}
