<?php

declare(strict_types=1);

namespace App\DTOs\Mail;

use App\Support\Temporal\ProcessSeal;
use Illuminate\Contracts\Encryption\Encrypter;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class SystemMailData implements TemporalSerializable
{
    public function __construct(
        public ?string $tenantId,
        public string $deliveryKey,
        public string $mailClass,
        public string $sealedEnvelope,
    ) {}

    public static function sealed(Encrypter $encrypter, ?string $tenantId, string $deliveryKey, string $mailClass, string $serializedEnvelope): self
    {
        return new self($tenantId, $deliveryKey, $mailClass, ProcessSeal::seal($encrypter, self::binding($tenantId, $deliveryKey, $mailClass), $serializedEnvelope));
    }

    public function unsealedEnvelope(Encrypter $encrypter): string
    {
        return ProcessSeal::unseal($encrypter, self::binding($this->tenantId, $this->deliveryKey, $this->mailClass), $this->sealedEnvelope);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: isset($data['tenant_id']) ? (string) $data['tenant_id'] : null,
            deliveryKey: (string) $data['delivery_key'],
            mailClass: (string) $data['mail_class'],
            sealedEnvelope: (string) $data['sealed_envelope'],
        );
    }

    /**
     * @return array{tenant_id: string|null, delivery_key: string, mail_class: string, sealed_envelope: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'delivery_key' => $this->deliveryKey,
            'mail_class' => $this->mailClass,
            'sealed_envelope' => $this->sealedEnvelope,
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
     * @return array{tenant_id: string|null, delivery_key: string, mail_class: string}
     */
    private static function binding(?string $tenantId, string $deliveryKey, string $mailClass): array
    {
        return ['tenant_id' => $tenantId, 'delivery_key' => $deliveryKey, 'mail_class' => $mailClass];
    }
}
