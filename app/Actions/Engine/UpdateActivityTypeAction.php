<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ActivityType;
use App\Traits\Engine\ValidatesTypeName;
use Illuminate\Validation\ValidationException;

class UpdateActivityTypeAction
{
    use ValidatesTypeName;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(ActivityType $activityType, array $input): ActivityType
    {
        $activityType->fill([
            'name' => $this->validatedTypeName($input, $activityType->getTable(), (string) $activityType->getKey()),
        ]);
        $activityType->save();

        return $activityType;
    }
}
