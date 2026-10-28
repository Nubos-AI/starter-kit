<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\FieldPermission;
use App\Models\Role;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\RoleInputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncFieldPermissionsAction
{
    public function __construct(
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly RoleInputRules $rules,
    ) {}

    /**
     * @param  list<array{field_definition_id: string, can_read: bool, can_write: bool}>  $permissions
     *
     * @throws Throwable
     */
    public function execute(Role $role, array $permissions): void
    {
        Validator::make(['field_permissions' => $permissions], $this->rules->fieldPermissions('present'))->validate();

        $restrictions = array_map(
            static fn (array $permission): array => [
                ...$permission,
                'can_write' => $permission['can_read'] && $permission['can_write'],
            ],
            $permissions,
        );

        $this->subsetGuard->assertMayGrantFieldPermissions($role, $restrictions);

        DB::transaction(function () use ($role, $restrictions): void {
            foreach ($restrictions as $restriction) {
                $this->applyRestriction($role, $restriction);
            }
        });
    }

    /**
     * @param  array{field_definition_id: string, can_read: bool, can_write: bool}  $restriction
     */
    private function applyRestriction(Role $role, array $restriction): void
    {
        $match = [
            'role_id' => $role->getKey(),
            'field_definition_id' => $restriction['field_definition_id'],
        ];

        if ($restriction['can_read'] && $restriction['can_write']) {
            FieldPermission::query()->where($match)->first()?->delete();

            return;
        }

        FieldPermission::query()->updateOrCreate($match, [
            'can_read' => $restriction['can_read'],
            'can_write' => $restriction['can_write'],
        ]);
    }
}
