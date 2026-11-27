<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Enums\Temporal\ProcessFailureType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ApplicationFailure;
use Throwable;

readonly class ActivityFailures
{
    public function __construct(private Repository $config) {}

    public function rejected(ValidationException $rejection): ApplicationFailure
    {
        /** @var array<string, list<string>> $errors */
        $errors = array_map(array_values(...), $rejection->errors());

        return new ApplicationFailure(
            $this->shortened('The process step was rejected on the fields '.implode(', ', array_keys($errors)).'.'),
            ProcessFailureType::Rejected->value,
            true,
            EncodedValues::fromValues([$errors]),
        );
    }

    public function compact(Throwable $failure, bool $isRetryable): ApplicationFailure
    {
        if ($failure instanceof ApplicationFailure) {
            return new ApplicationFailure(
                $this->shortened($failure->getOriginalMessage()),
                $failure->getType(),
                $failure->isNonRetryable() || !$isRetryable,
                $failure->getDetails(),
            );
        }

        return new ApplicationFailure(
            $this->shortened(class_basename($failure).': '.FailureText::of($failure)),
            $failure::class,
            !$isRetryable,
        );
    }

    public function isConflictIn(Throwable $failure): bool
    {
        return $this->applicationFailureIn($failure, fn (string $type): bool => $type === ProcessFailureType::Conflicting->value) instanceof ApplicationFailure;
    }

    /**
     * @param  class-string<Throwable>  $exception
     */
    public function isRaisedIn(Throwable $failure, string $exception): bool
    {
        return $this->applicationFailureIn($failure, fn (string $type): bool => is_a($type, $exception, true)) instanceof ApplicationFailure;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function rejectionErrorsIn(Throwable $failure): ?array
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if (!$cause instanceof ApplicationFailure || $cause->getType() !== ProcessFailureType::Rejected->value) {
                continue;
            }

            $details = $cause->getDetails();
            $errors = $details->count() > 0 ? $details->getValue(0, 'array') : [];

            return $this->normalizedErrors(is_array($errors) ? $errors : []);
        }

        return null;
    }

    public function shortened(string $message): string
    {
        return Str::limit($message, max(1, (int) $this->config->get('temporal.failures.message_limit')));
    }

    /**
     * @param  callable(string): bool  $matchesType
     */
    private function applicationFailureIn(Throwable $failure, callable $matchesType): ?ApplicationFailure
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof ApplicationFailure && $matchesType($cause->getType())) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $errors
     * @return array<string, list<string>>
     */
    private function normalizedErrors(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            $normalized[(string) $field] = array_values(array_map(strval(...), array_filter((array) $messages, is_scalar(...))));
        }

        return $normalized;
    }
}
