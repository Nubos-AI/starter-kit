<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Contracts\Temporal\ProcessInputFingerprintInterface;
use BackedEnum;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;
use UnitEnum;

readonly class ProcessFingerprints
{
    public function __construct(private Repository $config) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public function of(array $arguments): string
    {
        return hash_hmac(
            'sha256',
            json_encode($this->normalized($arguments), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            (string) $this->config->get('app.key'),
        );
    }

    private function normalized(mixed $value): mixed
    {
        return match (true) {
            $value instanceof ProcessInputFingerprintInterface => ['class' => $value::class, 'fingerprint' => $value->processFingerprint()],
            $value instanceof TemporalSerializable => ['class' => $value::class, 'payload' => $this->normalized($value->toTemporalPayload())],
            $value instanceof BackedEnum => ['class' => $value::class, 'value' => $value->value],
            $value instanceof UnitEnum => ['class' => $value::class, 'case' => $value->name],
            $value instanceof Model => ['class' => $value::class, 'key' => $value->getKey()],
            $value instanceof JsonSerializable => ['class' => $value::class, 'payload' => $this->normalized($value->jsonSerialize())],
            is_array($value) => array_map($this->normalized(...), $value),
            is_object($value) => ['class' => $value::class, 'payload' => $this->normalized(get_object_vars($value))],
            default => $value,
        };
    }
}
