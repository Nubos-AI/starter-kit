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

it('refuses a deletion without a reason when the object type demands one before it opens a transaction', function (): void {
    $errors = null;

    $reached = WriteAttempt::reachedTheDatabase(function () use (&$errors): void {
        $errors = ($this->refusalOf)(($this->recordOfType)(true), []);
    });

    expect($reached)->toBeFalse()
        ->and($errors)->toHaveKey('deletion_reason');
});

it('refuses a reason that is not a string', function (): void {
    expect(($this->refusalOf)(($this->recordOfType)(true), ['deletion_reason' => ['Dublette']]))->toHaveKey('deletion_reason');
});

it('bounds the reason length to the stored column width whether or not the object type demands a reason', function (bool $requiresReason): void {
    expect(($this->refusalOf)(($this->recordOfType)($requiresReason), ['deletion_reason' => str_repeat('a', 256)]))
        ->toHaveKey('deletion_reason');
})->with([
    'a reason is demanded' => true,
    'no reason is demanded' => false,
]);

it('lets a supplied reason through to the write', function (): void {
    expect(WriteAttempt::reachedTheDatabase(
        fn (): mixed => $this->action->execute(($this->recordOfType)(true), ['deletion_reason' => 'Dublette']),
    ))->toBeTrue();
});

it('lets a deletion without a reason through when the object type does not demand one', function (): void {
    expect(WriteAttempt::reachedTheDatabase(fn (): mixed => $this->action->execute(($this->recordOfType)(false), [])))->toBeTrue();
});
