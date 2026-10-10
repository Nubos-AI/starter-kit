<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\FieldGroup;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateFieldGroupAction
{
    public function __construct(private readonly SystemObjectTypeGuard $systemObjectTypeGuard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(FieldGroup $group, array $input): FieldGroup
    {
        $this->systemObjectTypeGuard->assertFieldsAreManageable($group->objectType);

        $validated = Validator::make($input, [
            'i18n_labels' => ['nullable', 'array'],
            'i18n_descriptions' => ['nullable', 'array'],
            'position' => ['integer', 'min:0'],
        ])->validate();

        foreach (['i18n_labels', 'i18n_descriptions', 'position'] as $attribute) {
            if (array_key_exists($attribute, $validated)) {
                $group->{$attribute} = $validated[$attribute];
            }
        }

        $group->save();

        return $group;
    }
}
