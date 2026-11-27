<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\ObjectTypeCapability;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\ObjectType;
use App\Support\Modules\ModuleAccess;

class ObjectTypeCapabilityGuard
{
    public function __construct(
        private readonly ObjectTypeBackingRegistry $registry,
        private readonly ?ModuleAccess $access = null,
    ) {}

    public function supports(ObjectType $type, ObjectTypeCapability|string $capability): bool
    {
        if (!($this->access?->allowsObjectType($type) ?? true)) {
            return false;
        }

        try {
            return $this->registry->for($type)->supports($capability);
        } catch (MissingObjectTypeBackingException) {
            return false;
        }
    }

    public function assertSupports(ObjectType $type, ObjectTypeCapability|string $capability): void
    {
        abort_unless($this->supports($type, $capability), 404);
    }
}
