<?php

declare(strict_types=1);

use App\Models\ApprovalProcess;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\PromotionRun;
use App\Support\Approvals\ApprovalAnchorPresenter;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Teams\TeamSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant);

    URL::defaults([TeamSegment::key() => 'personal']);

    $this->presenter = new ApprovalAnchorPresenter;

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('object-type'),
        'record_number' => 'REC-4711',
    ], [
        'objectType' => ModelStub::make(ObjectType::class, [
            'id' => ModelStub::ulid('object-type'),
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Firma',
        ]),
    ]);

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relations
     */
    $this->process = function (array $attributes = [], array $relations = []): ApprovalProcess {
        return ModelStub::make(ApprovalProcess::class, [
            'id' => ModelStub::ulid('approval-process'),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => null,
            'anchor_type' => null,
            'anchor_id' => null,
            ...$attributes,
        ], $relations);
    };

    $this->titleAlways = static function (string $title): void {
        $resolver = Mockery::mock(RecordTitleResolver::class);
        $resolver->shouldReceive('titleFor')->andReturn($title);

        app()->instance(RecordTitleResolver::class, $resolver);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(RecordTitleResolver::class);
    URL::defaults([TeamSegment::key() => null]);
});

it('shows the record title to a viewer who may read the record', function (): void {
    GateSpy::allowing('view');
    ($this->titleAlways)('Musterfirma GmbH');

    $process = ($this->process)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->presenter->label($process, $this->viewer))->toBe('Musterfirma GmbH');
});

it('falls back to the bare record number for a viewer who may not read the record', function (): void {
    GateSpy::allowing();
    ($this->titleAlways)('Musterfirma GmbH');

    $process = ($this->process)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->presenter->label($process, $this->viewer))->toBe('REC-4711');
});

it('never resolves a title at all when there is no viewer', function (): void {
    $process = ($this->process)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->presenter->label($process, null))->toBe('REC-4711');
});

it('falls back to the record key when the record itself is gone', function (): void {
    GateSpy::allowing('view');

    $process = ($this->process)(['record_id' => ModelStub::ulid('vanished')], ['record' => null]);

    expect($this->presenter->label($process, $this->viewer))->toBe(ModelStub::ulid('vanished'))
        ->and($this->presenter->referenceLabel($process))->toBe(ModelStub::ulid('vanished'));
});

it('names the anchor kind and its creation moment in utc for a process without a record', function (): void {
    $process = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], [
        'anchor' => ModelStub::make(PromotionRun::class, [
            'id' => ModelStub::ulid('promotion-run'),
            'tenant_id' => $this->tenant->getKey(),
            'created_at' => CarbonImmutable::parse('2026-02-03 14:05:00', 'UTC'),
        ]),
    ]);

    expect($this->presenter->referenceLabel($process))->toBe('Promotion vom 03.02.2026 14:05 UTC');
});

it('says the anchor no longer exists once it has been removed', function (): void {
    $process = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], ['anchor' => null]);

    expect($this->presenter->referenceLabel($process))->toContain('Promotion');
});

it('falls back to the generic process label and its start for an anchor kind it does not know', function (): void {
    $process = ($this->process)([
        'anchor_type' => 'App\\Models\\SomethingElse',
        'started_at' => CarbonImmutable::parse('2026-02-03 14:05:00', 'UTC'),
    ]);

    expect($this->presenter->referenceLabel($process))->toEndWith('vom 03.02.2026 14:05 UTC');
});

it('names the object type as the kind of a record bound process and the anchor kind otherwise', function (): void {
    $recordBound = ($this->process)(['record_id' => $this->record->getKey()], ['record' => $this->record]);
    $anchorBound = ($this->process)(['anchor_type' => PromotionRun::class]);

    expect($this->presenter->kindLabel($recordBound))->toBe('Firma')
        ->and($this->presenter->kindLabel($anchorBound))->toBe('Promotion');
});

it('shows an em dash as the kind when the record of a record bound process is gone', function (): void {
    $process = ($this->process)(['record_id' => ModelStub::ulid('vanished')], ['record' => null]);

    expect($this->presenter->kindLabel($process))->toBe('—');
});

it('withholds the anchor url from a viewer who may not view the anchor', function (): void {
    GateSpy::allowing();

    $process = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], [
        'anchor' => ModelStub::make(PromotionRun::class, [
            'id' => ModelStub::ulid('promotion-run'),
            'tenant_id' => $this->tenant->getKey(),
        ]),
    ]);

    expect($this->presenter->anchorUrl($process, $this->viewer))->toBeNull()
        ->and($this->presenter->anchorUrl($process, null))->toBeNull();
});

it('hands the anchor url to a viewer who may view the anchor', function (): void {
    GateSpy::allowing('view');

    $process = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], [
        'anchor' => ModelStub::make(PromotionRun::class, [
            'id' => ModelStub::ulid('promotion-run'),
            'tenant_id' => $this->tenant->getKey(),
        ]),
    ]);

    expect($this->presenter->anchorUrl($process, $this->viewer))->toContain(ModelStub::ulid('promotion-run'));
});

it('falls back to the decision page as the notification target when the anchor cannot be reached', function (): void {
    GateSpy::allowing();

    $process = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], ['anchor' => null]);

    expect($this->presenter->targetUrl($process, $this->viewer))
        ->toContain((string) $process->getKey());
});

it('phrases a record bound reference differently from an anchor bound one', function (): void {
    GateSpy::allowing('view');

    $recordBound = ($this->process)(['record_id' => $this->record->getKey()], ['record' => $this->record]);
    $anchorBound = ($this->process)([
        'anchor_type' => PromotionRun::class,
        'anchor_id' => ModelStub::ulid('promotion-run'),
    ], ['anchor' => null]);

    expect($this->presenter->processReference($recordBound))->toBe('zum Datensatz REC-4711')
        ->and($this->presenter->processReference($anchorBound))->toStartWith('„');
});
