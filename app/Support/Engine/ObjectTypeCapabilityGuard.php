<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\ObjectTypeCapability;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\ObjectType;

class ObjectTypeCapabilityGuard
{
    public function __construct(private readonly ObjectTypeBackingRegistry $registry) {}

    public function supports(ObjectType $type, ObjectTypeCapability $capability): bool
    {
        try {
            return $this->registry->for($type)->supports($capability);
        } catch (MissingObjectTypeBackingException) {
            return false;
        }
    }

    public function assertSupports(ObjectType $type, ObjectTypeCapability $capability): void
    {
        abort_unless($this->supports($type, $capability), 404);
    }
}
