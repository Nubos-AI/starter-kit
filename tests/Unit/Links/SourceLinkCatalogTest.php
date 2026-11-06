<?php

declare(strict_types=1);

use App\DTOs\Links\SourceLinkData;
use App\Handlers\Links\RecordSourceLinkResolver;
use App\Models\RecordActivity;
use App\Models\ReminderTask;
use App\Support\Links\SourceLinkCatalog;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticSourceLinkResolver;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('link-catalog-tenant');
    $this->viewer = AccessContext::user($this->tenant, [], 'link-catalog-viewer');

    $this->recordA = ModelStub::ulid('link-catalog-record-a');
    $this->recordB = ModelStub::ulid('link-catalog-record-b');
    $this->hiddenRecord = ModelStub::ulid('link-catalog-record-hidden');
    $this->mailA = ModelStub::ulid('link-catalog-mail-a');
    $this->mailHidden = ModelStub::ulid('link-catalog-mail-hidden');

    $this->records = new StaticSourceLinkResolver('record', [
        $this->recordA => new SourceLinkData('C-1', '/records/a'),
        $this->recordB => new SourceLinkData('C-2', '/records/b'),
    ]);
    $this->mails = new StaticSourceLinkResolver('email_message', [
        $this->mailA => new SourceLinkData('E-Mail: Angebot', '/mails/a'),
    ]);

    config(['links.sources' => [
        'record' => RecordSourceLinkResolver::class,
        'email_message' => StaticSourceLinkResolver::class,
    ]]);
    app()->instance(RecordSourceLinkResolver::class, $this->records);
    app()->instance(StaticSourceLinkResolver::class, $this->mails);

    $this->reminder = fn (string $seed, array $attributes): ReminderTask => ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => null,
        'source_type' => null,
        'source_id' => null,
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('resolves the record and the source of every item with one call per source type', function (): void {
    $items = collect([
        ($this->reminder)('catalog-1', ['record_id' => $this->recordA, 'source_type' => 'email_message', 'source_id' => $this->mailA]),
        ($this->reminder)('catalog-2', ['record_id' => $this->recordB]),
        ($this->reminder)('catalog-3', ['record_id' => $this->recordA, 'source_type' => 'email_message', 'source_id' => $this->mailHidden]),
    ]);

    $links = app(SourceLinkCatalog::class)->linksFor($this->viewer, $items);

    expect($this->records->resolveCalls)->toBe([[$this->recordA, $this->recordB]])
        ->and($this->mails->resolveCalls)->toBe([[$this->mailA, $this->mailHidden]])
        ->and($links[ModelStub::ulid('catalog-1')])->toBe([
            ['key' => 'record', 'id' => $this->recordA, 'label' => 'C-1', 'href' => '/records/a'],
            ['key' => 'email_message', 'id' => $this->mailA, 'label' => 'E-Mail: Angebot', 'href' => '/mails/a'],
        ])
        ->and($links[ModelStub::ulid('catalog-2')])->toBe([
            ['key' => 'record', 'id' => $this->recordB, 'label' => 'C-2', 'href' => '/records/b'],
        ])
        ->and($links[ModelStub::ulid('catalog-3')])->toBe([
            ['key' => 'record', 'id' => $this->recordA, 'label' => 'C-1', 'href' => '/records/a'],
        ]);
});

it('leaves out a record the viewer may not open and a source type no package registers any more', function (): void {
    $activity = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('catalog-activity'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->hiddenRecord,
        'source_type' => 'uninstalled_source',
        'source_id' => $this->mailA,
    ]);

    $links = app(SourceLinkCatalog::class)->linksFor($this->viewer, collect([$activity]));

    expect($links)->toBe([ModelStub::ulid('catalog-activity') => []])
        ->and($this->mails->resolveCalls)->toBe([]);
});

it('does not repeat the record link when the source names the very same record', function (): void {
    $item = ($this->reminder)('catalog-same', ['record_id' => $this->recordA, 'source_type' => 'record', 'source_id' => $this->recordA]);

    $links = app(SourceLinkCatalog::class)->linksFor($this->viewer, collect([$item]));

    expect($links[ModelStub::ulid('catalog-same')])->toBe([
        ['key' => 'record', 'id' => $this->recordA, 'label' => 'C-1', 'href' => '/records/a'],
    ])->and($this->records->resolveCalls)->toBe([[$this->recordA]]);
});

it('asks no resolver at all for items without record and source', function (): void {
    $links = app(SourceLinkCatalog::class)->linksFor($this->viewer, collect([($this->reminder)('catalog-bare', [])]));

    expect($links)->toBe([ModelStub::ulid('catalog-bare') => []])
        ->and($this->records->resolveCalls)->toBe([])
        ->and($this->mails->resolveCalls)->toBe([]);
});
