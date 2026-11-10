<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Http\Resources\Timeline\TimelineEntryResource;
use App\Models\TimelineEntry;
use App\Support\Audit\AuditActorLabelResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->userId = ModelStub::ulid('label-user');

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "personal_access_tokens"') => [['id' => 42, 'name' => 'Zapier']],
        str_contains($sql, 'from "users"') => [['id' => $this->userId, 'first_name' => 'Mara', 'last_name' => 'Kessler']],
        default => [],
    });

    $this->entry = fn (string $seed, ?int $apiTokenId): TimelineEntry => ModelStub::make(TimelineEntry::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'actor_id' => $this->userId,
        'actor_type' => ActorType::User->value,
        'channel' => $apiTokenId === null ? 'web' : 'api',
        'api_token_id' => $apiTokenId,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('stamps the name of the api token next to the creator on an entry made through it', function (): void {
    $viaToken = ($this->entry)('via-token', 42);
    $viaBrowser = ($this->entry)('via-browser', null);

    (new AuditActorLabelResolver)->stampLabels(collect([$viaToken, $viaBrowser]));

    expect($viaToken->getAttribute('actor_label'))->toBe('Mara Kessler')
        ->and($viaToken->getAttribute('api_token_label'))->toBe('Zapier')
        ->and($viaBrowser->getAttribute('actor_label'))->toBe('Mara Kessler')
        ->and($viaBrowser->getAttribute('api_token_label'))->toBeNull();
});

it('leaves the token label empty once the token no longer exists', function (): void {
    $orphan = ($this->entry)('orphan', 7);

    (new AuditActorLabelResolver)->stampLabels(collect([$orphan]));

    expect($orphan->getAttribute('api_token_label'))->toBeNull();
});

it('asks for no token at all when no entry came through the api', function (): void {
    (new AuditActorLabelResolver)->stampLabels(collect([($this->entry)('plain', null)]));

    $tokenQueries = array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'personal_access_tokens'),
    );

    expect($tokenQueries)->toBe([]);
});

it('hands the stamped token label to the timeline payload next to the api channel', function (): void {
    $entry = ($this->entry)('payload', 42);
    $entry->setAttribute('occurred_at', CarbonImmutable::parse('2026-11-09 10:00:00'));

    (new AuditActorLabelResolver)->stampLabels(collect([$entry]));

    $payload = TimelineEntryResource::make($entry)->toArray(Request::create('/'));

    expect($payload['channel'])->toBe('api')
        ->and($payload['apiTokenLabel'])->toBe('Zapier')
        ->and($payload)->not->toHaveKey('apiTokenId');
});
