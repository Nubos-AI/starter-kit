<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RecordDiff;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->diff = app(RecordDiff::class);
    $this->redacted = (string) config('engine.diff.redacted_placeholder');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('audited-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('audited-object-type'),
    ]);

    /** @var callable(list<string>):AuditRecorder */
    $this->recorderKnowing = static function (array $encryptedKeys): AuditRecorder {
        app()->instance(EncryptedFieldKeys::class, new class($encryptedKeys) extends EncryptedFieldKeys
        {
            /**
             * @param  list<string>  $keys
             */
            public function __construct(private readonly array $keys) {}

            /**
             * @return list<string>
             */
            public function forObjectType(string $objectTypeId): array
            {
                return $this->keys;
            }
        });

        return app(AuditRecorder::class);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('replaces an encrypted value on both sides of the diff with the sentinel', function (): void {
    $changes = $this->diff->between(['ssn' => 'secret-123'], ['ssn' => 'secret-456'], ['ssn']);

    expect($changes)->toBe([['field_key' => 'ssn', 'old' => $this->redacted, 'new' => $this->redacted]]);
});

it('keeps a null on an encrypted field as a null rather than sentinel noise', function (): void {
    $changes = $this->diff->between([], ['ssn' => 'secret-123'], ['ssn']);

    expect($changes)->toBe([['field_key' => 'ssn', 'old' => null, 'new' => $this->redacted]]);
});

it('leaves an unencrypted field untouched and skips a field that did not change', function (): void {
    $changes = $this->diff->between(
        ['amount' => 1, 'note' => 'same'],
        ['amount' => 2, 'note' => 'same'],
        [],
    );

    expect($changes)->toBe([['field_key' => 'amount', 'old' => 1, 'new' => 2]]);
});

it('never sends an encrypted plaintext to the audit table', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->recorderKnowing)(['ssn'])->record(
        $this->record,
        ['ssn' => 'secret-123'],
        ['ssn' => 'secret-456'],
        2,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('insert into "audit_entries"')
        ->and($shape->hasBinding('secret-123'))->toBeFalse()
        ->and($shape->hasBinding('secret-456'))->toBeFalse()
        ->and($shape->hasBinding(json_encode($this->redacted, JSON_THROW_ON_ERROR)))->toBeTrue()
        ->and($shape->hasBinding('ssn'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->record->getKey()))->toBeTrue();
});

it('sends the plain value of an unencrypted field to the audit table', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->recorderKnowing)([])->record(
        $this->record,
        ['amount' => 1],
        ['amount' => 2],
        2,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('insert into "audit_entries"')
        ->and($shape->hasBinding('1'))->toBeTrue()
        ->and($shape->hasBinding('2'))->toBeTrue()
        ->and($shape->hasBinding(json_encode($this->redacted, JSON_THROW_ON_ERROR)))->toBeFalse();
});

it('writes nothing at all when the two payloads are identical', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->recorderKnowing)([])->record(
        $this->record,
        ['amount' => 1],
        ['amount' => 1],
        2,
    ));

    expect($shape)->toBeNull();
});

it('refuses to audit anything that is not a custom record', function (): void {
    expect(fn (): mixed => ($this->recorderKnowing)([])->record($this->tenant, [], ['a' => 1], 1))
        ->toThrow(InvalidArgumentException::class);
});
