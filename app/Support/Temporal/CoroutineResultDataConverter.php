<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use ReflectionClass;
use ReflectionType;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\SerializationContext;
use Temporal\DataConverter\SerializationContextAwareInterface;
use Temporal\DataConverter\Type;
use Traversable;

class CoroutineResultDataConverter implements DataConverterInterface, SerializationContextAwareInterface
{
    public function __construct(private readonly DataConverter $converter) {}

    /**
     * @param  string|ReflectionClass<object>|ReflectionType|Type<bool>|null  $type
     */
    public function fromPayload(Payload $payload, mixed $type): mixed
    {
        return $this->converter->fromPayload($payload, $this->withoutCoroutineType(Type::create($type)));
    }

    public function toPayload(mixed $value): Payload
    {
        return $this->converter->toPayload($value);
    }

    public function getSerializationContext(): ?SerializationContext
    {
        return $this->converter->getSerializationContext();
    }

    public function withSerializationContext(?SerializationContext $context): static
    {
        return clone ($this, ['converter' => $this->converter->withSerializationContext($context)]);
    }

    /**
     * @param  Type<bool>  $type
     * @return Type<bool>
     */
    private function withoutCoroutineType(Type $type): Type
    {
        $isCoroutine = $type->getName() === 'iterable'
            || is_a($type->getName(), Traversable::class, true);

        return $isCoroutine ? new Type(Type::TYPE_ANY, true) : $type;
    }
}
