<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;

class StaticFieldVisibility extends FieldVisibilityResolver
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, list<string>>  $readableByObjectType
     */
    public function __construct(private readonly array $readableByObjectType = []) {}

    /**
     * @param  array<string, list<string>>  $readableByObjectType
     */
    public static function install(array $readableByObjectType): self
    {
        $resolver = new self($readableByObjectType);

        app()->instance(FieldVisibilityResolver::class, $resolver);

        return $resolver;
    }

    public static function forget(): void
    {
        app()->forgetInstance(FieldVisibilityResolver::class);
    }

    /**
     * @return list<string>
     */
    public function readableFieldKeys(User $user, string $objectTypeId): array
    {
        $this->askedFor[] = $objectTypeId;

        return $this->readableByObjectType[$objectTypeId] ?? [];
    }
}
