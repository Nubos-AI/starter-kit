<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\FieldDefinition;
use App\Models\FieldGroup;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteFieldGroupAction
{
    public function __construct(private readonly SystemObjectTypeGuard $systemObjectTypeGuard) {}

    /**
     * @throws Throwable
     */
    public function execute(FieldGroup $group): void
    {
        $this->systemObjectTypeGuard->assertFieldsAreManageable($group->objectType);

        DB::transaction(function () use ($group): void {
            FieldDefinition::query()
                ->where('field_group_id', $group->getKey())
                ->update(['field_group_id' => null]);

            $group->delete();
        });
    }
}
