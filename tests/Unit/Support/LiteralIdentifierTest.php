<?php

declare(strict_types=1);

use App\Support\Sql\LiteralIdentifier;

test('a lower snake identifier passes through unchanged', function (string $key): void {
    expect((new LiteralIdentifier)->lowerSnake($key))->toBe($key);
})->with(['status', 'due_at', 'field_2', 'a', '_leading', 'x9_y']);

test('a lower snake identifier rejects every byte outside the allowlist', function (string $key): void {
    expect(fn (): string => (new LiteralIdentifier)->lowerSnake($key))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'upper case' => ['Status'],
    'quote' => ["status'"],
    'semicolon' => ['status;drop'],
    'dash' => ['due-at'],
    'space' => ['due at'],
    'dot' => ['data.status'],
    'backslash' => ['status\\'],
    'nul byte' => ["status\0"],
    'unicode' => ['stätus'],
]);

test('a mixed alphanumeric identifier keeps upper case and rejects the rest', function (): void {
    $identifier = new LiteralIdentifier;

    expect($identifier->mixedAlnum('idx_Custom_Records_9'))->toBe('idx_Custom_Records_9')
        ->and(fn (): string => $identifier->mixedAlnum('idx-custom'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $identifier->mixedAlnum('idx"custom'))
        ->toThrow(InvalidArgumentException::class);
});

test('a time zone keeps slashes, plus and minus and rejects the rest', function (): void {
    $identifier = new LiteralIdentifier;

    expect($identifier->timeZone('Europe/Berlin'))->toBe('Europe/Berlin')
        ->and($identifier->timeZone('Etc/GMT+2'))->toBe('Etc/GMT+2')
        ->and($identifier->timeZone('UTC'))->toBe('UTC')
        ->and(fn (): string => $identifier->timeZone("Europe/Berlin'"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $identifier->timeZone('Europe Berlin'))
        ->toThrow(InvalidArgumentException::class);
});

test('an empty identifier is refused by every allowlist', function (): void {
    $identifier = new LiteralIdentifier;

    expect(fn (): string => $identifier->lowerSnake(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $identifier->mixedAlnum(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $identifier->timeZone(''))->toThrow(InvalidArgumentException::class);
});
