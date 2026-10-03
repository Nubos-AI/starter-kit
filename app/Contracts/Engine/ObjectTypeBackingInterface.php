<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\BackingRelationDescriptor;
use App\DTOs\Engine\FieldDescriptor;
use App\Enums\Authorization\CrudAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\Engine\ObjectTypeCapability;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ObjectTypeBackingInterface
{
    /**
     * @return class-string<Model>
     */
    public function modelClass(ObjectType $type): string;

    /**
     * @return Builder<covariant Model>
     */
    public function newQuery(ObjectType $type): Builder;

    public function keyName(): string;

    public function hasRows(ObjectType $type): bool;

    public function find(ObjectType $type, string $identifier): ?Model;

    public function titleFor(Model $record): string;

    public function titleColumn(ObjectType $type): ?string;

    /**
     * @return list<FieldDescriptor>
     */
    public function fields(ObjectType $type): array;

    /**
     * @return array<string, BackingRelationDescriptor>
     */
    public function relations(ObjectType $type): array;

    public function supports(ObjectTypeCapability $capability): bool;

    public function permissionFor(ObjectType $type, CrudAction|ObjectTypeAbility $ability): ?string;

    public function indexPath(ObjectType $type): string;
}
