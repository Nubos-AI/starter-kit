<?php

declare(strict_types=1);

use App\Actions\Notes\CreateRecordNoteAction;
use App\Actions\Notes\UpdateRecordNoteAction;
use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Support\Timeline\NoteTimelineWriter;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('note-action-tenant');
    $this->author = AccessContext::actAs(AccessContext::user($this->tenant, [], 'note-author'));
    $this->recordId = ModelStub::ulid('note-action-record');

    $this->timelineWriter = new class extends NoteTimelineWriter
    {
        public int $calls = 0;

        public function __construct() {}

        public function record(CustomRecord $record, array $notes): void
        {
            $this->calls++;
        }
    };

    $this->createAction = fn (): CreateRecordNoteAction => new CreateRecordNoteAction($this->timelineWriter);
    $this->updateAction = fn (): UpdateRecordNoteAction => new UpdateRecordNoteAction;

    $this->noteWith = fn (string $body): RecordNote => ModelStub::make(RecordNote::class, [
        'id' => ModelStub::ulid('note-action-note'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->recordId,
        'author_id' => $this->author->getKey(),
        'body' => $body,
    ]);

    $this->errorKeysOf = static function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects an empty body and a body of nothing but whitespace', function (): void {
    expect(($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => ''])))
        ->toContain('body')
        ->and(($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => "   \n\t "])))
        ->toContain('body');
});

it('rejects a body of ten thousand and one characters', function (): void {
    expect(($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => str_pad('', 10_001, 'a')])))
        ->toContain('body');
});

it('demands a record for every note', function (): void {
    expect(($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => 'Rückruf vereinbart.'])))
        ->toBe(['record_id']);
});

it('never names the body when only the record is missing', function (): void {
    expect(($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => 'Rückruf vereinbart.'])))
        ->not->toContain('body');
});

it('accepts a body of exactly ten thousand characters and looks the record up in the bound tenant', function (): void {
    $reached = WriteAttempt::reachedTheDatabase(fn (): RecordNote => ($this->createAction)()->execute([
        'record_id' => $this->recordId,
        'body' => str_pad('', 10_000, 'a'),
    ]));

    expect($reached)->toBeTrue();
});

it('rejects an empty or overlong body on an update and leaves the note untouched', function (): void {
    $note = ($this->noteWith)('Rückruf vereinbart.');

    expect(($this->errorKeysOf)(fn () => ($this->updateAction)()->execute($note, ['body' => ''])))->toBe(['body'])
        ->and(($this->errorKeysOf)(fn () => ($this->updateAction)()->execute($note, ['body' => str_pad('', 10_001, 'a')])))->toBe(['body'])
        ->and(($this->errorKeysOf)(fn () => ($this->updateAction)()->execute($note, [])))->toBe(['body'])
        ->and($note->body)->toBe('Rückruf vereinbart.');
});

it('measures the body length after the surrounding whitespace is trimmed off', function (): void {
    $note = ($this->noteWith)('Rückruf vereinbart.');
    $padded = '   '.str_pad('', 10_000, 'a')."  \n";

    expect(WriteAttempt::reachedTheDatabase(fn (): RecordNote => ($this->updateAction)()->execute($note, ['body' => $padded])))
        ->toBeTrue()
        ->and(($this->errorKeysOf)(fn () => ($this->updateAction)()->execute($note, ['body' => str_pad('', 10_001, 'a')])))
        ->toBe(['body']);
});

it('writes no timeline entry when the note never passes validation', function (): void {
    ($this->errorKeysOf)(fn () => ($this->createAction)()->execute(['body' => '']));

    expect($this->timelineWriter->calls)->toBe(0);
});
