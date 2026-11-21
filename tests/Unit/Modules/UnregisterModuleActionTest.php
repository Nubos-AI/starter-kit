<?php

declare(strict_types=1);

use App\Actions\Modules\ForgetModuleAction;
use App\Actions\Modules\UnregisterModuleAction;
use App\Support\Modules\ModuleRegistry;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps the registry row of the module as a disabled unregistration so a later Composer run does not register it again', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => (new UnregisterModuleAction)->execute('nubos/documents'));

    expect($attempt)->not->toBeNull()
        ->and($attempt->sql)->toBe('update "modules" set "installed_at" = ?, "version" = ?, "disabled" = ?, "updated_at" = ? where "name" = ?')
        ->and(array_slice($attempt->bindings, 0, 3))->toEqual([null, null, true])
        ->and($attempt->bindings[4])->toBe('nubos/documents');
});

it('forgets only the unregistration of a module, never a registered one', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => (new ForgetModuleAction)->execute('nubos/documents'));

    expect($attempt)->not->toBeNull()
        ->and($attempt->sql)->toBe('delete from "modules" where "name" = ? and "installed_at" is null')
        ->and($attempt->bindings)->toBe(['nubos/documents']);
});

it('reads only the unregistered rows as withdrawn modules', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => (new ModuleRegistry)->withdrawn());

    expect($attempt)->not->toBeNull()
        ->and($attempt->sql)->toBe('select "name" from "modules" where "installed_at" is null order by "name" asc');
});
