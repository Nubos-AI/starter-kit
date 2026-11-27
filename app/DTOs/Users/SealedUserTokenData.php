<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use App\Support\Temporal\ProcessSeal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class SealedUserTokenData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $sealedToken,
        public string $expiresAt,
    ) {}

    public static function sealed(Encrypter $encrypter, string $tenantId, string $userId, IssuedUserTokenData $issued): self
    {
        return new self($tenantId, $userId, ProcessSeal::seal($encrypter, self::binding($tenantId, $userId), $issued->token), $issued->expiresAt->toIso8601String());
    }

    public static function notIssued(string $tenantId, string $userId): self
    {
        return new self($tenantId, $userId, '', '');
    }

    public function isIssued(): bool
    {
        return $this->sealedToken !== '';
    }

    public function unsealedToken(Encrypter $encrypter): string
    {
        return ProcessSeal::unseal($encrypter, self::binding($this->tenantId, $this->userId), $this->sealedToken);
    }

    public function expiresAt(): CarbonInterface
    {
        return CarbonImmutable::parse($this->expiresAt);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            userId: (string) $data['user_id'],
            sealedToken: (string) $data['sealed_token'],
            expiresAt: (string) $data['expires_at'],
        );
    }

    /**
     * @return array{tenant_id: string, user_id: string, sealed_token: string, expires_at: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'sealed_token' => $this->sealedToken,
            'expires_at' => $this->expiresAt,
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
