<?php

declare(strict_types=1);

namespace App\DTOs\Recurrence;

use App\Enums\Recurrence\RecurrenceFrequency;
use App\Exceptions\Recurrence\InvalidRecurrenceRuleException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class RecurrenceRule
{
    public static int $maxInterval = 99;

    /**
     * @param  list<string>  $exceptions
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function __construct(
        public readonly RecurrenceFrequency $frequency,
        public readonly int $interval,
        public readonly ?CarbonImmutable $endsAt,
        public readonly array $exceptions = [],
    ) {
        if ($interval < 1 || $interval > self::$maxInterval) {
            throw InvalidRecurrenceRuleException::forInterval($interval, self::$maxInterval);
        }
    }

    /**
     * @param  list<string>|null  $exceptions
     *
     * @throws InvalidRecurrenceRuleException
     */
    public static function fromAttributes(RecurrenceFrequency|string|null $frequency, ?int $interval, ?CarbonInterface $endsAt, ?array $exceptions = null): ?self
    {
        $frequency = is_string($frequency) ? RecurrenceFrequency::tryFrom($frequency) : $frequency;

        if (!$frequency instanceof RecurrenceFrequency) {
            return null;
        }

        return new self($frequency, $interval ?? 1, $endsAt?->toImmutable()->utc(), $exceptions ?? []);
    }

    public static function keyOf(CarbonInterface $occurrence): string
    {
        return $occurrence->toImmutable()->utc()->toIso8601ZuluString();
    }

    public function excludes(CarbonInterface $occurrence): bool
    {
        return in_array(self::keyOf($occurrence), $this->exceptions, true);
    }

    /**
     * @return list<string>
     */
    public function exceptionsWith(CarbonInterface $occurrence): array
    {
        $exceptions = array_values(array_unique([...$this->exceptions, self::keyOf($occurrence)]));

        sort($exceptions);

        return $exceptions;
    }

    /**
     * @return array{frequency: string, interval: int, endsAt: string|null}
     */
    public function toPayload(): array
    {
        return [
            'frequency' => $this->frequency->value,
            'interval' => $this->interval,
            'endsAt' => $this->endsAt?->toISOString(),
        ];
    }
}
