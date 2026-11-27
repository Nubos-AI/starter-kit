<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use App\Contracts\Temporal\ProcessInputFingerprintInterface;
use App\Support\Temporal\ProcessSeal;
use Illuminate\Contracts\Encryption\Encrypter;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ChangeUserPasswordData implements ProcessInputFingerprintInterface, TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $sealedInput,
        private ?string $inputDigest = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function sealed(Encrypter $encrypter, string $tenantId, string $userId, array $input): self
    {
        $plaintext = (string) json_encode($input);

        return new self($tenantId, $userId, ProcessSeal::seal($encrypter, self::binding($tenantId, $userId), $plaintext), ProcessSeal::digest($plaintext));
    }

    /**
     * @return array<string, mixed>
     */
    public function unsealedInput(Encrypter $encrypter): array
    {
        $input = json_decode(ProcessSeal::unseal($encrypter, self::binding($this->tenantId, $this->userId), $this->sealedInput), true);

        return is_array($input) ? $input : [];
    }

    public function processFingerprint(): string
    {
        return "{$this->tenantId}|{$this->userId}|".($this->inputDigest ?? $this->sealedInput);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            userId: (string) $data['user_id'],
            sealedInput: (string) $data['sealed_input'],
        );
    }

    /**
     * @return array{tenant_id: string, user_id: string, sealed_input: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'sealed_input' => $this->sealedInput,
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
