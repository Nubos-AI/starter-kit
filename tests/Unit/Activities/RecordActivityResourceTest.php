<?php

declare(strict_types=1);

use App\Http\Resources\RecordActivityResource;
use App\Models\RecordActivity;
use Illuminate\Http\Request;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->activity = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('activity-resource'),
        'tenant_id' => ModelStub::ulid('activity-resource-tenant'),
        'record_id' => null,
        'source_type' => 'email_message',
        'source_id' => ModelStub::ulid('activity-resource-mail'),
        'subject' => 'Angebot besprochen',
        'occurred_at' => '2026-10-01 09:00:00',
    ]);
});

it('exposes the record and source references and the pre-resolved quick links of its own activity', function (): void {
    $link = ['key' => 'email_message', 'id' => ModelStub::ulid('activity-resource-mail'), 'label' => 'E-Mail: Angebot', 'href' => '/mails/1'];

    $payload = RecordActivityResource::withLinksFrom($this->activity, [
        ModelStub::ulid('activity-resource') => [$link],
    ])->toArray(Request::create('/'));

    expect($payload['recordId'])->toBeNull()
        ->and($payload['sourceType'])->toBe('email_message')
        ->and($payload['sourceId'])->toBe(ModelStub::ulid('activity-resource-mail'))
        ->and($payload['links'])->toBe([$link]);
});

it('carries no quick link when nobody resolved one', function (): void {
    expect(RecordActivityResource::make($this->activity)->toArray(Request::create('/'))['links'])->toBe([]);
});
