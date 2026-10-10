<?php

declare(strict_types=1);

use App\Enums\Authorization\PermissionEffect;
use App\Support\Authorization\PermissionContext;

beforeEach(function (): void {
    /** @var callable(array<string, PermissionEffect>, array<string, true>):PermissionContext */
    $this->team = fn (array $overrides = [], array $grants = []): PermissionContext => new PermissionContext(
        false,
        $overrides,
        $grants,
    );
});

test('a granted ability is allowed', function (): void {
    $context = new PermissionContext(false, [], ['records.view' => true]);

    expect($context->allows('records.view'))->toBeTrue()
        ->and($context->ownVerdict('records.view'))->toBeTrue();
});

test('an ability nobody mentions is denied and undecided at the own level', function (): void {
    $context = new PermissionContext(false, [], []);

    expect($context->allows('records.view'))->toBeFalse()
        ->and($context->ownVerdict('records.view'))->toBeNull();
});

test('a deny override beats a grant from a role', function (): void {
    $context = new PermissionContext(
        false,
        ['records.view' => PermissionEffect::Deny],
        ['records.view' => true],
    );

    expect($context->allows('records.view'))->toBeFalse();
});

test('an allow override grants an ability no role carries', function (): void {
    $context = new PermissionContext(false, ['records.view' => PermissionEffect::Allow], []);

    expect($context->allows('records.view'))->toBeTrue();
});

test('escalation wins over a deny override', function (): void {
    $context = new PermissionContext(true, ['records.view' => PermissionEffect::Deny], []);

    expect($context->allows('records.view'))->toBeTrue()
        ->and($context->ownVerdict('records.view'))->toBeTrue();
});

test('escalation allows everything including unknown abilities', function (): void {
    $context = new PermissionContext(true, [], []);

    expect($context->allows('something.nobody.defined'))->toBeTrue();
});

test('an undecided own level falls through to the team', function (): void {
    $context = new PermissionContext(
        false,
        [],
        [],
        fn (): PermissionContext => ($this->team)([], ['records.view' => true]),
    );

    expect($context->allows('records.view'))->toBeTrue();
});

test('an own deny does not fall through to a granting team', function (): void {
    $context = new PermissionContext(
        false,
        ['records.view' => PermissionEffect::Deny],
        [],
        fn (): PermissionContext => ($this->team)([], ['records.view' => true]),
    );

    expect($context->allows('records.view'))->toBeFalse();
});

test('an own grant survives a denying team', function (): void {
    $context = new PermissionContext(
        false,
        [],
        ['records.view' => true],
        fn (): PermissionContext => ($this->team)(['records.view' => PermissionEffect::Deny], []),
    );

    expect($context->allows('records.view'))->toBeTrue();
});

test('a deny on the team beats a grant on the same team', function (): void {
    $context = new PermissionContext(
        false,
        [],
        [],
        fn (): PermissionContext => ($this->team)(
            ['records.view' => PermissionEffect::Deny],
            ['records.view' => true],
        ),
    );

    expect($context->allows('records.view'))->toBeFalse();
});

test('an undecided team denies', function (): void {
    $context = new PermissionContext(false, [], [], fn (): PermissionContext => ($this->team)());

    expect($context->allows('records.view'))->toBeFalse();
});

test('a missing team denies', function (): void {
    $context = new PermissionContext(false, [], [], fn (): ?PermissionContext => null);

    expect($context->allows('records.view'))->toBeFalse();
});

test('the team stays unresolved while the own level decides', function (): void {
    $calls = 0;

    $context = new PermissionContext(
        false,
        [],
        ['records.view' => true],
        function () use (&$calls): PermissionContext {
            $calls++;

            return ($this->team)();
        },
    );

    $context->allows('records.view');

    expect($calls)->toBe(0);
});

test('the team is resolved at most once across many questions', function (): void {
    $calls = 0;

    $context = new PermissionContext(
        false,
        [],
        [],
        function () use (&$calls): PermissionContext {
            $calls++;

            return ($this->team)([], ['records.view' => true]);
        },
    );

    $context->allows('records.view');
    $context->allows('records.update');
    $context->allows('records.delete');

    expect($calls)->toBe(1);
});

test('the own verdict never consults the team', function (): void {
    $calls = 0;

    $context = new PermissionContext(
        false,
        [],
        [],
        function () use (&$calls): PermissionContext {
            $calls++;

            return ($this->team)([], ['records.view' => true]);
        },
    );

    expect($context->ownVerdict('records.view'))->toBeNull()
        ->and($calls)->toBe(0);
});
