<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Enums\Authorization\PermissionEffect;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\RoleInputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncPermissionOverridesAction
{
    public function __construct(
        private readonly RoleInputRules $rules,
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly AuthorizationDirectory $directory,
    ) {}

    /**
     * @param  list<string>  $deniedPermissionIds
     *
     * @throws Throwable
     */
    public function execute(PermissionHolderInterface $holder, array $deniedPermissionIds): void
    {
        Validator::make(
            ['denied_permission_ids' => $deniedPermissionIds],
            $this->rules->deniedPermissionIds('present'),
        )->validate();

        $denied = $this->directory->existingPermissionIds($deniedPermissionIds);
        $current = $this->directory->deniedPermissionIdsOf($holder);

        $this->subsetGuard->assertMayOverridePermissions([
            ...array_diff($denied, $current),
            ...array_diff($current, $denied),
        ]);

        DB::transaction(function () use ($holder, $denied): void {
            $stale = $holder->permissionOverrides()
                ->where('effect', PermissionEffect::Deny->value);

            if ($denied !== []) {
                $stale->whereNotIn('permission_id', $denied);
            }

            $stale->delete();

            foreach ($denied as $permissionId) {
                $holder->permissionOverrides()->updateOrCreate([
                    'permission_id' => $permissionId,
                    'scope_type' => null,
                    'scope_id' => null,
                ], [
                    'effect' => PermissionEffect::Deny,
                ]);
            }
        });

        $holder->forgetResolvedRoles();
    }
}
