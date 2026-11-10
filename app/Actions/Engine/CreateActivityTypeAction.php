<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ActivityType;
use App\Traits\Engine\ValidatesTypeName;
use Illuminate\Validation\ValidationException;

class CreateActivityTypeAction
{
    use ValidatesTypeName;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(array $input): ActivityType
    {
        return ActivityType::query()->create([
            'name' => $this->validatedTypeName($input, (new ActivityType)->getTable()),
        ]);
    }
}
