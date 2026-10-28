<?php

declare(strict_types=1);

use App\Actions\Modules\UnregisterModuleAction;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('removes the registry row of the module instead of blanking its version', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => (new UnregisterModuleAction)->execute('nubos/documents'));

    expect($attempt)->not->toBeNull()
        ->and($attempt->sql)->toStartWith('delete from "modules"')
        ->and($attempt->hasBinding('nubos/documents'))->toBeTrue();
});
