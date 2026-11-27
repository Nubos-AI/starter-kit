<?php

declare(strict_types=1);

namespace App\Support\Recurrence;

use App\Contracts\Recurrence\RecurringInterface;
use App\DTOs\Recurrence\RecurrenceRule;
use App\Enums\Recurrence\RecurrenceFrequency;
use App\Enums\Recurrence\SeriesScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class RecurrenceRuleValidator
{
    /**
     * @var list<string>
     */
    public static array $fields = ['recurrence_frequency', 'recurrence_interval', 'recurrence_ends_at'];

    /**
     * @var list<string>
     */
    public static array $scopeFields = ['scope', 'occurrence_at'];

    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'recurrence_frequency' => ['nullable', 'string', new Enum(RecurrenceFrequency::class)],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:'.RecurrenceRule::$maxInterval],
            'recurrence_ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function scopeRules(): array
    {
        return [
            'scope' => ['nullable', 'string', new Enum(SeriesScope::class)],
            'occurrence_at' => ['nullable', 'required_if:scope,'.SeriesScope::Occurrence->value, 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function occurrenceOf(array $validated, RecurringInterface $series): ?CarbonImmutable
    {
        $rule = $series->recurrenceRule();
        $start = $series->seriesStart();
        $anchor = $series->seriesAnchor();

        if (($validated['scope'] ?? null) !== SeriesScope::Occurrence->value || !$rule instanceof RecurrenceRule || !$start instanceof CarbonInterface || !$anchor instanceof CarbonInterface) {
            return null;
        }

        $occurrenceAt = CarbonImmutable::parse((string) $validated['occurrence_at'])->utc();

        if ($occurrenceAt->lessThan($start) || !$this->expander->isOccurrence($anchor, $rule, $occurrenceAt)) {
            throw ValidationException::withMessages([
                'occurrence_at' => __('i18n.backend.support.recurrence.recurrence_rule_validator.not_an_occurrence'),
            ]);
        }

        return $occurrenceAt;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function touches(array $validated): bool
    {
        return Arr::hasAny($validated, self::$fields);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{recurrence_frequency: string|null, recurrence_interval: int|null, recurrence_ends_at: CarbonImmutable|null}
     *
     * @throws ValidationException
     */
    public function attributes(array $input, ?CarbonInterface $startsAt): array
    {
        $rule = $this->ruleOf($input);

        if (!$rule instanceof RecurrenceRule) {
            return ['recurrence_frequency' => null, 'recurrence_interval' => null, 'recurrence_ends_at' => null];
        }

        if (!$startsAt instanceof CarbonInterface) {
            throw ValidationException::withMessages([
                'recurrence_frequency' => __('i18n.backend.support.recurrence.recurrence_rule_validator.needs_a_start'),
            ]);
        }

        if ($rule->endsAt instanceof CarbonImmutable && $rule->endsAt->lessThan($startsAt)) {
            throw ValidationException::withMessages([
                'recurrence_ends_at' => __('i18n.backend.support.recurrence.recurrence_rule_validator.ends_before_start'),
            ]);
        }

        return [
            'recurrence_frequency' => $rule->frequency->value,
            'recurrence_interval' => $rule->interval,
            'recurrence_ends_at' => $rule->endsAt,
        ];
    }

    /**
     * @param  array{recurrence_frequency: string|null, recurrence_interval: int|null, recurrence_ends_at: CarbonImmutable|null}  $attributes
     * @return list<string>|null
     */
    public function exceptionsAfterChange(RecurringInterface $series, array $attributes, ?CarbonInterface $startsAt): ?array
    {
        $rule = $series->recurrenceRule();
        $previousStart = $series->seriesStart();
        $isSameRule = $rule instanceof RecurrenceRule
            && $attributes['recurrence_frequency'] === $rule->frequency->value
            && $attributes['recurrence_interval'] === $rule->interval;

        if (!$isSameRule || $rule->exceptions === [] || !$startsAt instanceof CarbonInterface || !$previousStart instanceof CarbonInterface) {
            return null;
        }

        $timezone = (string) config('reports.timezone');
        $shift = $previousStart->toImmutable()->setTimezone($timezone)->diff($startsAt->toImmutable()->setTimezone($timezone));

        return array_map(
            static fn (string $exception): string => RecurrenceRule::keyOf(CarbonImmutable::parse($exception)->setTimezone($timezone)->add($shift)),
            $rule->exceptions,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ruleOf(array $input): ?RecurrenceRule
    {
        $frequency = $input['recurrence_frequency'] ?? null;
        $interval = $input['recurrence_interval'] ?? null;

        return RecurrenceRule::fromAttributes(
            is_string($frequency) || $frequency instanceof RecurrenceFrequency ? $frequency : null,
            is_numeric($interval) ? (int) $interval : null,
            $this->endOfLocalDay($input['recurrence_ends_at'] ?? null),
        );
    }

    private function endOfLocalDay(mixed $endsAt): ?CarbonImmutable
    {
        if (!is_string($endsAt) && !$endsAt instanceof CarbonInterface) {
            return null;
        }

        $timezone = (string) config('reports.timezone');

        return CarbonImmutable::parse($endsAt, $timezone)
            ->setTimezone($timezone)
            ->endOfDay()
            ->utc();
    }
}
