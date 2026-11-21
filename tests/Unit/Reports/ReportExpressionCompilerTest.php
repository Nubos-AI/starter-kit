<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Exceptions\Reports\UnsupportedAggregationException;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\SystemFilterFields;
use App\Support\Reports\ReportExpressionCompiler;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registry = new IndexRegistry;
    $this->systemFields = new SystemFilterFields;
    $this->zone = 'Europe/Berlin';
    $this->compiler = new ReportExpressionCompiler($this->registry, $this->systemFields, $this->zone);

    $this->isoRegex = '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])([T ][0-9]{2}:[0-9]{2}(:[0-9]{2}(\.[0-9]+)?)?(Z|[+-][0-9]{2}:?[0-9]{2})?)?$';

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->makeField = function (string $key, FieldType $type, array $attributes = []): FieldDefinition {
        $field = new FieldDefinition;
        $field->key = $key;
        $field->field_type = $type;
        $field->is_encrypted = false;
        $field->is_translatable = false;
        $field->config = null;

        foreach ($attributes as $name => $value) {
            $field->{$name} = $value;
        }

        return $field;
    };

    /** @var callable(string):string */
    $this->numericExpression = function (string $key): string {
        return $this->registry->sortExpression(($this->makeField)($key, FieldType::Decimal));
    };

    /** @var callable(string, FieldType, string):string */
    $this->temporalExpression = function (string $key, FieldType $type, string $zone): string {
        $json = "data->>'{$key}'";

        if ($type === FieldType::Date) {
            return "(CASE WHEN {$json} ~ '{$this->isoRegex}' AND pg_input_is_valid({$json}, 'timestamp') THEN (({$json})::timestamp AT TIME ZONE '{$zone}') END)";
        }

        return "(CASE WHEN {$json} ~ '{$this->isoRegex}' AND pg_input_is_valid({$json}, 'timestamptz') THEN ({$json})::timestamptz END)";
    };

    /** @var callable(callable):string */
    $this->rejectionMessage = function (callable $operation): string {
        try {
            $operation();
        } catch (UnsupportedAggregationException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected UnsupportedAggregationException was not thrown.');
    };

    /** @var callable(callable):string */
    $this->thrownClass = function (callable $operation): string {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception::class;
        }

        $this->fail('Expected an exception was not thrown.');
    };
});

test('the numeric aggregate expression is derived character-identically from the index registry', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);

    $registryExpression = $this->registry->sortExpression($money);

    expect($registryExpression)->toContain('::numeric')
        ->and($registryExpression)->toContain('CASE WHEN')
        ->and($this->compiler->aggregateExpression($money, AggregationType::Sum))
        ->toBe('SUM('.$registryExpression.')')
        ->and($this->compiler->aggregateExpression($money, AggregationType::Avg))
        ->toBe('AVG('.$registryExpression.')');
});

test('a rollup field carries the numeric guard although it uses no numeric index', function (): void {
    $rollup = ($this->makeField)('total', FieldType::Rollup);
    $decimalTwin = ($this->makeField)('total', FieldType::Decimal);

    expect($rollup->usesNumericIndex())->toBeFalse()
        ->and($decimalTwin->usesNumericIndex())->toBeTrue()
        ->and($this->compiler->aggregateExpression($rollup, AggregationType::Sum))
        ->toBe('SUM('.$this->registry->sortExpression($decimalTwin).')')
        ->and($this->compiler->aggregateExpression($rollup, AggregationType::Sum))
        ->toContain('::numeric');
});

test('count compiles to a plain row count with and without an aggregation field', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);
    $text = ($this->makeField)('title', FieldType::TextShort);

    expect($this->compiler->aggregateExpression(null, AggregationType::Count))->toBe('COUNT(*)')
        ->and($this->compiler->aggregateExpression($money, AggregationType::Count))->toBe('COUNT(*)')
        ->and($this->compiler->aggregateExpression($text, AggregationType::Count))->toBe('COUNT(*)');
});

test('min and max over a decimal field carry the numeric guard', function (): void {
    $decimal = ($this->makeField)('score', FieldType::Decimal);

    $numeric = ($this->numericExpression)('score');

    expect($this->compiler->aggregateExpression($decimal, AggregationType::Min))->toBe('MIN('.$numeric.')')
        ->and($this->compiler->aggregateExpression($decimal, AggregationType::Max))->toBe('MAX('.$numeric.')')
        ->and($numeric)->toBe($this->registry->sortExpression($decimal));
});

test('min and max over date and datetime carry the guarded timestamp expression', function (): void {
    $date = ($this->makeField)('closed_on', FieldType::Date);
    $dateTime = ($this->makeField)('closed_at', FieldType::DateTime);

    $dateExpression = ($this->temporalExpression)('closed_on', FieldType::Date, 'Europe/Berlin');
    $dateTimeExpression = ($this->temporalExpression)('closed_at', FieldType::DateTime, 'Europe/Berlin');

    expect($this->compiler->aggregateExpression($date, AggregationType::Min))->toBe('MIN('.$dateExpression.')')
        ->and($this->compiler->aggregateExpression($date, AggregationType::Max))->toBe('MAX('.$dateExpression.')')
        ->and($this->compiler->aggregateExpression($dateTime, AggregationType::Min))->toBe('MIN('.$dateTimeExpression.')')
        ->and($this->compiler->aggregateExpression($dateTime, AggregationType::Max))->toBe('MAX('.$dateTimeExpression.')');
});

test('distinct count over a text field wraps the plain text expression', function (): void {
    $text = ($this->makeField)('title', FieldType::TextShort);

    expect($this->compiler->aggregateExpression($text, AggregationType::DistinctCount))
        ->toBe('COUNT(DISTINCT '.$this->registry->sortExpression($text).')')
        ->and($this->registry->sortExpression($text))->not->toContain('::numeric');
});

test('distinct count reuses the grouping expression of the very same field', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);
    $text = ($this->makeField)('title', FieldType::TextShort);
    $rollup = ($this->makeField)('total', FieldType::Rollup);
    $owner = ($this->makeField)(SystemFilterField::Owner->value, FieldType::SingleSelect);

    foreach ([$money, $text, $rollup, $owner] as $field) {
        expect($this->compiler->aggregateExpression($field, AggregationType::DistinctCount))
            ->toBe('COUNT(DISTINCT '.$this->compiler->groupingExpression($field).')');
    }

    expect($this->compiler->groupingExpression($money))->toContain('::numeric')
        ->and($this->compiler->groupingExpression($rollup))->not->toContain('::numeric')
        ->and($this->compiler->aggregateExpression($owner, AggregationType::DistinctCount))
        ->toBe('COUNT(DISTINCT owner_id)');
});

test('a regular field groups by the very expression the index registry produces', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);
    $text = ($this->makeField)('title', FieldType::TextShort);
    $date = ($this->makeField)('closed_on', FieldType::Date);

    foreach ([$money, $text, $date] as $field) {
        expect($this->compiler->groupingExpression($field))->toBe($this->registry->sortExpression($field));
    }

    expect($this->compiler->groupingExpression($text))->toContain("data->>'title'");
});

test('every bucket level wraps the guarded temporal expression with the injected zone', function (): void {
    $date = ($this->makeField)('closed_on', FieldType::Date);
    $dateTime = ($this->makeField)('closed_at', FieldType::DateTime);

    $dateExpression = ($this->temporalExpression)('closed_on', FieldType::Date, 'Europe/Berlin');
    $dateTimeExpression = ($this->temporalExpression)('closed_at', FieldType::DateTime, 'Europe/Berlin');

    $levels = [
        [GroupingBucket::Day, 'day'],
        [GroupingBucket::Week, 'week'],
        [GroupingBucket::Month, 'month'],
        [GroupingBucket::Quarter, 'quarter'],
        [GroupingBucket::Year, 'year'],
    ];

    foreach ($levels as [$bucket, $unit]) {
        expect($this->compiler->bucketExpression($date, $bucket))
            ->toBe("date_trunc('{$unit}', {$dateExpression}, 'Europe/Berlin')")
            ->and($this->compiler->bucketExpression($dateTime, $bucket))
            ->toBe("date_trunc('{$unit}', {$dateTimeExpression}, 'Europe/Berlin')");
    }

    expect($levels)->toHaveCount(count(GroupingBucket::cases()));
});

test('the date branch converts wall time while the datetime branch keeps the stored offset', function (): void {
    $date = ($this->makeField)('closed_on', FieldType::Date);
    $dateTime = ($this->makeField)('closed_at', FieldType::DateTime);

    $dateBucket = $this->compiler->bucketExpression($date, GroupingBucket::Month);
    $dateTimeBucket = $this->compiler->bucketExpression($dateTime, GroupingBucket::Month);

    expect($dateBucket)->toContain($this->isoRegex)
        ->and($dateBucket)->toContain("pg_input_is_valid(data->>'closed_on', 'timestamp')")
        ->and($dateBucket)->toContain("AT TIME ZONE 'Europe/Berlin'")
        ->and($dateBucket)->toContain("date_trunc('month'")
        ->and($dateBucket)->not->toContain('::timestamptz')
        ->and($dateTimeBucket)->toContain($this->isoRegex)
        ->and($dateTimeBucket)->toContain("pg_input_is_valid(data->>'closed_at', 'timestamptz')")
        ->and($dateTimeBucket)->toContain('::timestamptz')
        ->and($dateTimeBucket)->not->toContain('AT TIME ZONE');
});

test('the bucket and the temporal discard counter share one value expression', function (): void {
    $dateTime = ($this->makeField)('closed_at', FieldType::DateTime);

    $counter = $this->compiler->discardedTemporalValueCountExpression($dateTime);
    $prefix = "(COUNT(data->>'closed_at') - COUNT(";
    $valueExpression = substr($counter, strlen($prefix), -2);

    expect($counter)->toStartWith($prefix)
        ->and($counter)->toEndWith('))')
        ->and($valueExpression)->not->toBe('')
        ->and($valueExpression)->toContain('pg_input_is_valid')
        ->and($this->compiler->bucketExpression($dateTime, GroupingBucket::Day))
        ->toBe("date_trunc('day', {$valueExpression}, 'Europe/Berlin')");
});

test('a bucket on a non temporal field is rejected', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);
    $text = ($this->makeField)('title', FieldType::TextShort);
    $boolean = ($this->makeField)('active', FieldType::Boolean);

    foreach ([$money, $text, $boolean] as $field) {
        expect(fn () => $this->compiler->bucketExpression($field, GroupingBucket::Month))
            ->toThrow(UnsupportedAggregationException::class);
    }

    $message = ($this->rejectionMessage)(fn () => $this->compiler->bucketExpression($text, GroupingBucket::Month));

    expect($message)->toContain('text_short');
});

test('an unsupported aggregation and field type pair is rejected', function (): void {
    $text = ($this->makeField)('title', FieldType::TextShort);
    $date = ($this->makeField)('closed_on', FieldType::Date);

    expect(fn () => $this->compiler->aggregateExpression($text, AggregationType::Sum))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->aggregateExpression($date, AggregationType::Avg))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->aggregateExpression($text, AggregationType::Min))
        ->toThrow(UnsupportedAggregationException::class);

    $message = ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($text, AggregationType::Sum));

    expect($message)->toContain('sum')
        ->and($message)->toContain('text_short');
});

test('the aggregation type enum is the only source of the allowed field types', function (): void {
    $mismatches = [];
    $pairs = 0;

    foreach (AggregationType::cases() as $aggregation) {
        foreach (FieldType::cases() as $fieldType) {
            $field = ($this->makeField)('probe', $fieldType);
            $rejected = false;
            $pairs++;

            try {
                $this->compiler->aggregateExpression($field, $aggregation);
            } catch (UnsupportedAggregationException $exception) {
                $rejected = true;
            }

            if ($rejected === $aggregation->allowsFieldType($fieldType)) {
                $mismatches[] = $aggregation->value.':'.$fieldType->value;
            }
        }
    }

    expect($mismatches)->toBe([])
        ->and($pairs)->toBe(count(AggregationType::cases()) * count(FieldType::cases()));
});

test('an encrypted field is rejected on every expression path with its own message', function (): void {
    $encrypted = ($this->makeField)('amount', FieldType::Money, ['is_encrypted' => true]);
    $text = ($this->makeField)('title', FieldType::TextShort);

    $aggregate = ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($encrypted, AggregationType::Sum));
    $grouping = ($this->rejectionMessage)(fn () => $this->compiler->groupingExpression($encrypted));
    $bucket = ($this->rejectionMessage)(fn () => $this->compiler->bucketExpression($encrypted, GroupingBucket::Month));
    $counted = ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($encrypted, AggregationType::Count));
    $fieldTypeMessage = ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($text, AggregationType::Sum));

    expect($aggregate)->toBe($grouping)
        ->and($grouping)->toBe($bucket)
        ->and($bucket)->toBe($counted)
        ->and($aggregate)->not->toBe($fieldTypeMessage)
        ->and($aggregate)->not->toBe('');
});

test('a translatable field is rejected on every expression path', function (): void {
    $translatable = ($this->makeField)('title', FieldType::TextShort, ['is_translatable' => true]);
    $encrypted = ($this->makeField)('title', FieldType::TextShort, ['is_encrypted' => true]);

    expect(fn () => $this->compiler->groupingExpression($translatable))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->aggregateExpression($translatable, AggregationType::DistinctCount))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->aggregateExpression($translatable, AggregationType::Count))
        ->toThrow(UnsupportedAggregationException::class);

    $translatableMessage = ($this->rejectionMessage)(fn () => $this->compiler->groupingExpression($translatable));
    $encryptedMessage = ($this->rejectionMessage)(fn () => $this->compiler->groupingExpression($encrypted));

    expect($translatableMessage)->not->toBe($encryptedMessage)
        ->and($translatableMessage)->not->toBe('');
});

test('a missing field is rejected for every aggregation except count', function (): void {
    $rejected = [];

    foreach (AggregationType::cases() as $aggregation) {
        if ($aggregation === AggregationType::Count) {
            continue;
        }

        expect(fn () => $this->compiler->aggregateExpression(null, $aggregation))
            ->toThrow(UnsupportedAggregationException::class);

        $rejected[] = $aggregation->value;
    }

    expect($rejected)->toBe(['sum', 'avg', 'min', 'max', 'distinct_count'])
        ->and($this->compiler->aggregateExpression(null, AggregationType::Count))->toBe('COUNT(*)');
});

test('no rejection message reveals a field key or a field label', function (): void {
    $key = 'topsecretkey';
    $label = 'Topsecret Label';
    $labels = ['i18n_labels' => ['en' => $label, 'de' => $label]];

    $text = ($this->makeField)($key, FieldType::TextShort, $labels);
    $encrypted = ($this->makeField)($key, FieldType::Money, [...$labels, 'is_encrypted' => true]);
    $translatable = ($this->makeField)($key, FieldType::TextShort, [...$labels, 'is_translatable' => true]);
    $systemField = ($this->makeField)(SystemFilterField::Owner->value, FieldType::Money);

    $messages = [
        ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($text, AggregationType::Sum)),
        ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression($encrypted, AggregationType::Sum)),
        ($this->rejectionMessage)(fn () => $this->compiler->groupingExpression($translatable)),
        ($this->rejectionMessage)(fn () => $this->compiler->bucketExpression($text, GroupingBucket::Month)),
        ($this->rejectionMessage)(fn () => $this->compiler->discardedNumericValueCountExpression($text)),
        ($this->rejectionMessage)(fn () => $this->compiler->discardedNumericValueCountExpression($systemField)),
        ($this->rejectionMessage)(fn () => $this->compiler->aggregateExpression(null, AggregationType::Sum)),
    ];

    foreach ($messages as $message) {
        expect($message)->not->toBe('')
            ->and(strtolower($message))->not->toContain('topsecret')
            ->and($message)->not->toContain($key)
            ->and($message)->not->toContain($label)
            ->and($message)->not->toContain(SystemFilterField::Owner->value);
    }

    expect(array_unique($messages))->toHaveCount(7)
        ->and($messages)->toHaveCount(7);
});

test('a field key carrying quotes or a semicolon is rejected unwrapped', function (string $key): void {
    $numericField = ($this->makeField)($key, FieldType::Money);
    $dateField = ($this->makeField)($key, FieldType::Date);

    expect(($this->thrownClass)(fn () => $this->compiler->aggregateExpression($numericField, AggregationType::Sum)))
        ->toBe(InvalidArgumentException::class)
        ->and(($this->thrownClass)(fn () => $this->compiler->groupingExpression($numericField)))
        ->toBe(InvalidArgumentException::class)
        ->and(($this->thrownClass)(fn () => $this->compiler->bucketExpression($dateField, GroupingBucket::Month)))
        ->toBe(InvalidArgumentException::class)
        ->and(($this->thrownClass)(fn () => $this->compiler->discardedNumericValueCountExpression($numericField)))
        ->toBe(InvalidArgumentException::class);
})->with([
    'a quote and a semicolon smuggling a statement' => ["a'; DROP TABLE custom_records; --"],
    'a double quote breaking out of the identifier' => ['a"b'],
]);

test('a field key failing only the identifier pattern is rejected as well', function (): void {
    foreach (['Amount', '1amount'] as $key) {
        $field = ($this->makeField)($key, FieldType::Money);

        expect(($this->thrownClass)(fn () => $this->compiler->aggregateExpression($field, AggregationType::Sum)))
            ->toBe(InvalidArgumentException::class)
            ->and(($this->thrownClass)(fn () => $this->compiler->groupingExpression($field)))
            ->toBe(InvalidArgumentException::class);
    }

    $safe = ($this->makeField)('amount', FieldType::Money);

    expect($this->compiler->groupingExpression($safe))->toContain("data->>'amount'");
});

test('the time zone passes an allowlist before it reaches the expression', function (): void {
    $date = ($this->makeField)('closed_on', FieldType::Date);

    $unsafe = new ReportExpressionCompiler($this->registry, $this->systemFields, "Europe/Berlin'; DROP TABLE custom_records; --");
    $berlin = new ReportExpressionCompiler($this->registry, $this->systemFields, 'Europe/Berlin');
    $utc = new ReportExpressionCompiler($this->registry, $this->systemFields, 'UTC');

    expect(($this->thrownClass)(fn () => $unsafe->bucketExpression($date, GroupingBucket::Month)))
        ->toBe(InvalidArgumentException::class)
        ->and($berlin->bucketExpression($date, GroupingBucket::Month))->toContain("'Europe/Berlin'")
        ->and($utc->bucketExpression($date, GroupingBucket::Month))->toContain("'UTC'")
        ->and($utc->bucketExpression($date, GroupingBucket::Month))->not->toContain('Europe/Berlin');
});

test('the discard counters subtract the usable values from the present values', function (): void {
    $money = ($this->makeField)('amount', FieldType::Money);
    $dateTime = ($this->makeField)('closed_at', FieldType::DateTime);
    $date = ($this->makeField)('closed_on', FieldType::Date);

    expect($this->compiler->discardedNumericValueCountExpression($money))
        ->toBe("(COUNT(data->>'amount') - COUNT(".$this->registry->sortExpression($money).'))')
        ->and($this->compiler->discardedTemporalValueCountExpression($dateTime))
        ->toBe("(COUNT(data->>'closed_at') - COUNT(".($this->temporalExpression)('closed_at', FieldType::DateTime, 'Europe/Berlin').'))')
        ->and($this->compiler->discardedTemporalValueCountExpression($date))
        ->toBe("(COUNT(data->>'closed_on') - COUNT(".($this->temporalExpression)('closed_on', FieldType::Date, 'Europe/Berlin').'))')
        ->and($this->compiler->discardedNumericValueCountExpression($money))->not->toContain('COUNT(*)');
});

test('a discard counter is rejected for a foreign field type and for a system field', function (): void {
    $text = ($this->makeField)('title', FieldType::TextShort);
    $money = ($this->makeField)('amount', FieldType::Money);
    $numericSystemField = ($this->makeField)(SystemFilterField::Team->value, FieldType::Money);
    $temporalSystemField = ($this->makeField)(SystemFilterField::Owner->value, FieldType::DateTime);

    expect(fn () => $this->compiler->discardedNumericValueCountExpression($text))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->discardedTemporalValueCountExpression($money))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->discardedNumericValueCountExpression($numericSystemField))
        ->toThrow(UnsupportedAggregationException::class)
        ->and(fn () => $this->compiler->discardedTemporalValueCountExpression($temporalSystemField))
        ->toThrow(UnsupportedAggregationException::class);

    $foreignNumeric = ($this->rejectionMessage)(fn () => $this->compiler->discardedNumericValueCountExpression($text));
    $foreignTemporal = ($this->rejectionMessage)(fn () => $this->compiler->discardedTemporalValueCountExpression($money));
    $systemNumeric = ($this->rejectionMessage)(fn () => $this->compiler->discardedNumericValueCountExpression($numericSystemField));
    $systemTemporal = ($this->rejectionMessage)(fn () => $this->compiler->discardedTemporalValueCountExpression($temporalSystemField));

    expect($systemNumeric)->toBe($systemTemporal)
        ->and($systemNumeric)->not->toBe($foreignNumeric)
        ->and($systemNumeric)->not->toBe($foreignTemporal)
        ->and($foreignNumeric)->not->toBe($foreignTemporal);
});
