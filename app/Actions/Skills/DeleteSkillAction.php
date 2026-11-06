<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Models\Skill;

class DeleteSkillAction
{
    public function execute(Skill $skill): void
    {
        $skill->delete();
    }
}
