<?php

declare(strict_types=1);

use App\Actions\Engine\DeleteRecordAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->action = app(DeleteRecordAction::class);

    /** @var callable(bool):CustomRecord */
    $this->recordOfType = fn (bool $requiresReason): CustomRecord => ModelStub::make(
        CustomRecord::class,
        ['tenant_id' => $this->tenant->getKey(), 'version' => 1],
        ['objectType' => ModelStub::make(ObjectType::class, ['requires_deletion_reason' => $requiresReason])],
    );

    /** @var callable(CustomRecord, array<string, mixed>):array<string, list<string>> */
    $this->refusalOf = function (CustomRecord $record, array $input): array {
        try {
            $this->action->execute($record, $input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted a deletion it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a deletion without a reason when the object type demands one', function (): void {
    expect(($this->refusalOf)(($this->recordOfType)(true), []))->toHaveKey('deletion_reason');
});

it('refuses a reason that is not a string or exceeds the stored column width', function (): void {
    $record = ($this->recordOfType)(true);

    expect(($this->refusalOf)($record, ['deletion_reason' => ['Dublette']]))->toHaveKey('deletion_reason')
        ->and(($this->refusalOf)($record, ['deletion_reason' => str_repeat('a', 256)]))->toHaveKey('deletion_reason');
});

it('refuses before it opens a transaction, so a rejected deletion never touches a row', function (): void {
    $reached = WriteAttempt::reachedTheDatabase(function (): void {
        try {
            $this->action->execute(($this->recordOfType)(true), []);
        } catch (ValidationException) {
            return;
        }

        $this->fail('the action accepted a deletion it should have refused');
    });

    expect($reached)->toBeFalse();
});

it('lets a supplied reason through to the write', function (): void {
    expect(WriteAttempt::reachedTheDatabase(
        fn (): mixed => $this->action->execute(($this->recordOfType)(true), ['deletion_reason' => 'Dublette']),
    ))->toBeTrue();
});

it('lets a deletion without a reason through when the object type does not demand one', function (): void {
    expect(WriteAttempt::reachedTheDatabase(fn (): mixed => $this->action->execute(($this->recordOfType)(false), [])))->toBeTrue();
});

it('still bounds the reason length when the object type does not demand one', function (): void {
    expect(($this->refusalOf)(($this->recordOfType)(false), ['deletion_reason' => str_repeat('a', 256)]))
        ->toHaveKey('deletion_reason');
});
