<?php

declare(strict_types=1);

use App\Contracts\Approvals\ApprovalOutcomeHandler;
use App\Exceptions\Approvals\UnsupportedApprovalAnchorException;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\PromotionRun;
use App\Support\Approvals\ApprovalOutcomeRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):ApprovalOutcomeHandler */
    $this->handlerFor = fn (string $anchorType): ApprovalOutcomeHandler => new class($anchorType) implements ApprovalOutcomeHandler
    {
        public function __construct(private readonly string $handledAnchorType) {}

        public function anchorType(): string
        {
            return $this->handledAnchorType;
        }

        public function recordDecision(ApprovalProcess $process, ApprovalProcessStage $stage, ApprovalEvent $event): void {}

        public function applyApproved(ApprovalProcess $process): void {}

        public function applyRejected(ApprovalProcess $process, ?string $reason): void {}
    };
});

it('refuses an anchor type it knows no handler for with a german explanation', function (): void {
    $registry = new ApprovalOutcomeRegistry([($this->handlerFor)(PromotionRun::class)]);

    expect(fn (): ApprovalOutcomeHandler => $registry->for('App\Models\Unknown'))
        ->toThrow(UnsupportedApprovalAnchorException::class);

    try {
        $registry->for('App\Models\Unknown');
    } catch (UnsupportedApprovalAnchorException $exception) {
        expect($exception->getMessage())
            ->toContain('App\Models\Unknown')
            ->not->toContain('i18n.backend.');
    }
});

it('refuses to build a registry that carries the same anchor type twice', function (): void {
    expect(fn (): ApprovalOutcomeRegistry => new ApprovalOutcomeRegistry([
        ($this->handlerFor)(PromotionRun::class),
        ($this->handlerFor)(PromotionRun::class),
    ]))->toThrow(InvalidArgumentException::class);
});
