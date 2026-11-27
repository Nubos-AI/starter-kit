<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ReminderType;
use App\Traits\Engine\ValidatesTypeName;
use Illuminate\Validation\ValidationException;

class UpdateReminderTypeAction
{
    use ValidatesTypeName;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(ReminderType $reminderType, array $input): ReminderType
    {
        $reminderType->fill([
            'name' => $this->validatedTypeName($input, $reminderType->getTable(), (string) $reminderType->getKey()),
        ]);
        $reminderType->save();

        return $reminderType;
    }
}
