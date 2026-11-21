<?php

declare(strict_types=1);

use App\Support\Timeline\ChangeTimelineWriter;
use App\Support\Timeline\TimelineRecorder;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('files every audited field change under the field change source unless a module claims the field', function (): void {
    $writer = new ChangeTimelineWriter(Mockery::mock(TimelineRecorder::class));

    expect($writer->sourceKeyFor('headline'))->toBe('field_change')
        ->and($writer->sourceKeyFor('owner_id'))->toBe('field_change');
});
