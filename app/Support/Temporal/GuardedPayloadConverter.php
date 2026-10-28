<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Illuminate\Database\Eloquent\Model;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;
use Keepsuit\LaravelTemporal\DataConverter\LaravelPayloadConverter;
use Keepsuit\LaravelTemporal\Integrations\Eloquent\TemporalEloquentSerializer;
use ReflectionClass;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\Type;
use Temporal\Exception\DataConverterException;
use Throwable;

class GuardedPayloadConverter extends LaravelPayloadConverter
{
    private string $dataClass = 'Spatie\\LaravelData\\Data';

    public function fromPayload(Payload $payload, Type $type): mixed
    {
        if (!$type->isClass()) {
            return JsonConverter::fromPayload($payload, $type);
        }

        $typeName = $type->getName();

        if (!class_exists($typeName)) {
            return JsonConverter::fromPayload($payload, $type);
        }

        try {
            $data = \Safe\json_decode($payload->getData(), true, 512, self::JSON_FLAGS);
        } catch (Throwable $throwable) {
            throw new DataConverterException($throwable->getMessage(), $throwable->getCode(), $throwable);
        }

        $reflection = new ReflectionClass($typeName);
        $class = $reflection->getName();

        if ($reflection->implementsInterface(TemporalSerializable::class)) {
            /** @var class-string<TemporalSerializable> $class */
            return $class::fromTemporalPayload($data);
        }

        if ($reflection->isEnum()) {
            /** @var class-string<\BackedEnum> $class */
            return $class::from($data);
        }

        if (class_exists($this->dataClass) && $reflection->isSubclassOf($this->dataClass)) {
            /** @phpstan-ignore staticMethod.notFound */
            return $class::from($data);
        }

        if ($reflection->isSubclassOf(Model::class)) {
            /** @var class-string<Model> $class */
            return TemporalEloquentSerializer::fromPayload($class, $data);
        }

        return JsonConverter::fromPayload($payload, $type);
    }
}
