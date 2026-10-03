<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;

class FakeFieldVisibilityResolver extends FieldVisibilityResolver
{
    /**
     * @param  list<string>  $forbiddenRead
     * @param  list<string>  $forbiddenWrite
     */
    public function __construct(private array $forbiddenRead = [], private array $forbiddenWrite = [])
    {
        app()->instance(FieldVisibilityResolver::class, $this);
    }

    /**
     * @return list<string>
     */
    public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
    {
        return $this->forbiddenRead;
    }

    /**
     * @return list<string>
     */
    public function forbiddenWriteFieldKeys(User $user, string $objectTypeId): array
    {
        return $this->forbiddenWrite;
    }
}
