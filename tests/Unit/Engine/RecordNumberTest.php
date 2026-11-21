<?php

declare(strict_types=1);

use App\Exceptions\Engine\RecordNumberOverflowException;
use App\Models\ObjectType;
use App\Support\Engine\RecordNumberFormatter;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->formatter = new RecordNumberFormatter;

    /** @var callable(?string, ?string):ObjectType */
    $this->type = fn (?string $prefix, ?string $format = null): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('object-type-'.((string) $prefix).((string) $format)),
        'business_key_prefix' => $prefix,
        'record_number_format' => $format,
    ]);
});

it('combines the prefix with a zero based counter padded to the format width', function (): void {
    $type = ($this->type)('CO', RecordNumberFormatter::$defaultFormat);

    expect($this->formatter->format($type, 0))->toBe('CO-0000000000')
        ->and($this->formatter->format($type, 1))->toBe('CO-0000000001')
        ->and($this->formatter->format($type, 42))->toBe('CO-0000000042');
});

it('falls back to ten hash places when the type carries no format', function (): void {
    expect($this->formatter->format(($this->type)('DE', null), 7))->toBe('DE-0000000007')
        ->and($this->formatter->format(($this->type)('DE', ''), 7))->toBe('DE-0000000007');
});

it('keeps literal characters of the format and counts only the hashes', function (): void {
    expect($this->formatter->format(($this->type)('LE', '1#########'), 0))->toBe('LE-1000000000')
        ->and($this->formatter->format(($this->type)('LT', 'A######'), 0))->toBe('LT-A000000')
        ->and($this->formatter->format(($this->type)('LT', 'A######'), 1))->toBe('LT-A000001');
});

it('yields a shorter counter for a shorter format', function (): void {
    $type = ($this->type)('SH', '###');

    expect($this->formatter->format($type, 0))->toBe('SH-000')
        ->and($this->formatter->format($type, 1))->toBe('SH-001');
});

it('yields no record number at all for a type without a business key prefix', function (): void {
    expect($this->formatter->format(($this->type)(null, '####'), 3))->toBeNull()
        ->and($this->formatter->format(($this->type)('', '####'), 3))->toBeNull();
});

it('refuses a counter that outgrows its hash places instead of truncating it', function (): void {
    expect(fn (): ?string => $this->formatter->format(($this->type)('OV', '#'), 10))
        ->toThrow(RecordNumberOverflowException::class);
});
