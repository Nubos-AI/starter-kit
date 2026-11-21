<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\User;
use Illuminate\Http\Request;

interface UiModuleInterface
{
    public function key(): string;

    /** @return array<string, mixed> */
    public function share(Request $request): array;

    /**
     * @param  array<string, bool>  $permissions
     * @return list<array{target: string, items: list<array<string, mixed>>}>
     */
    public function navigation(User $user, array $permissions): array;
}
