<?php

declare(strict_types=1);

namespace App\Policies\Skills;

use App\Models\Skill;
use App\Models\User;

class SkillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('skills.view');
    }

    public function view(User $user, Skill $skill): bool
    {
        return $user->hasPermission('skills.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('skills.create');
    }

    public function update(User $user, Skill $skill): bool
    {
        return $user->hasPermission('skills.update');
    }

    public function delete(User $user, Skill $skill): bool
    {
        return $user->hasPermission('skills.delete');
    }
}
