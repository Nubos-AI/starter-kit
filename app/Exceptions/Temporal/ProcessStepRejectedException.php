<?php

declare(strict_types=1);

namespace App\Exceptions\Temporal;

use Illuminate\Validation\ValidationException;

class ProcessStepRejectedException extends ValidationException
{
    public static string $submissionField = 'submission';

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        return self::withMessages($errors);
    }

    public static function conflictingSubmission(?string $field = null): self
    {
        return self::withMessages([
            $field ?? self::$submissionField => __('i18n.backend.exceptions.temporal.process_step_rejected_exception.already_submitted_with_other_input'),
        ]);
    }

    /**
     * @param  array<string, string>  $fieldMap
     */
    public function onFields(array $fieldMap): self
    {
        $errors = [];

        foreach ($this->errors() as $field => $messages) {
            $errors[$fieldMap[$field] ?? $field] = array_values($messages);
        }

        return self::withMessages($errors);
    }
}
