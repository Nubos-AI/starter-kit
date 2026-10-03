<?php

declare(strict_types=1);

test('abilityIs refuses a map that never mentions the ability', function (): void {
    $expectFalse = abilityIs('automations.manage', false);

    expect($expectFalse([]))->toBeFalse()
        ->and($expectFalse(['reports.view' => false]))->toBeFalse();
});

test('abilityIs reads the ability it was given', function (): void {
    expect(abilityIs('automations.manage', true)(['automations.manage' => true]))->toBeTrue()
        ->and(abilityIs('automations.manage', true)(['automations.manage' => false]))->toBeFalse()
        ->and(abilityIs('automations.manage', false)(['automations.manage' => false]))->toBeTrue()
        ->and(abilityIs('automations.manage', false)(['automations.manage' => true]))->toBeFalse();
});
