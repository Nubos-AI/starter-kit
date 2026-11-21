<?php

declare(strict_types=1);

use App\Contracts\Modules\RecordUpdateExtensionInterface;
use App\Models\CustomRecord;
use App\Support\Modules\RecordExtensions;
use App\Support\Modules\RegisteredModuleComponents;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('fans the updated hook out to every update extension with the raw input', function (): void {
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('deal-record')]);
    $input = ['version' => 3, 'module_payload' => ['plan' => 'gold']];

    $first = Mockery::mock(RecordUpdateExtensionInterface::class);
    $first->shouldReceive('updated')->once()->with($record, $input);
    $second = Mockery::mock(RecordUpdateExtensionInterface::class);
    $second->shouldReceive('updated')->once()->with($record, $input);

    (new RecordExtensions(app(RegisteredModuleComponents::class), updates: [$first, $second]))->updated($record, $input);
});
