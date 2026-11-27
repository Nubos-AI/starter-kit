<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ReminderType;
use App\Traits\Engine\ValidatesTypeName;
use Illuminate\Validation\ValidationException;

class CreateReminderTypeAction
{
    use ValidatesTypeName;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(array $input): ReminderType
    {
        return ReminderType::query()->create([
            'name' => $this->validatedTypeName($input, (new ReminderType)->getTable()),
        ]);
    }
}
