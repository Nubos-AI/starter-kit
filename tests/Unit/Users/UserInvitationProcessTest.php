<?php

declare(strict_types=1);

use App\Actions\Users\IssueInvitationAction;
use App\Actions\Users\IssueInvitationTokenAction;
use App\Actions\Users\SendUserInvitationAction;
use App\Activities\Users\UserInvitationActivity;
use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\DTOs\Users\UserInvitationData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ActivityFailures;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->invitee = AccessContext::user($this->tenant, ['email' => 'invitee@example.test', 'invitation_token_hash' => 'old-hash'], 'invitee');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('loads the invitee of an invitation step only within the tenant of the workflow', function (): void {
    $activity = new UserInvitationActivity(
        Mockery::mock(IssueInvitationTokenAction::class),
        Mockery::mock(SendUserInvitationAction::class),
        new ProcessStepRunner(new ActivityFailures(Mockery::mock(Repository::class))),
        new TenantBinder,
        Mockery::mock(Encrypter::class),
    );

    $userId = (string) $this->invitee->getKey();
    $tenantId = (string) $this->tenant->getKey();

    $attempted = QueryShape::attemptedBy(fn () => $activity->issueInvitationToken(new UserInvitationData($tenantId, $userId, 'Inviter')));

    expect($attempted)->not->toBeNull()
        ->and($attempted->isScopedToTenant('users', $tenantId))->toBeTrue()
        ->and($attempted->isKeyedTo('users', $userId))->toBeTrue();
});

it('hands an invitation to the outbox without issuing or storing any invitation token', function (): void {
    $recorded = [];
    $outbox = Mockery::mock(OutboxWriter::class);
    $outbox->shouldReceive('record')
        ->once()
        ->andReturnUsing(function (string $topic, string $tenantId, OutboxSubject $subject, array $payload) use (&$recorded): void {
            $recorded = compact('topic', 'tenantId', 'subject', 'payload');
        });

    $inviter = AccessContext::user($this->tenant, ['first_name' => 'Ina', 'last_name' => 'Viter', 'email' => 'inviter@example.test'], 'inviter');

    (new IssueInvitationAction($outbox))->execute($this->invitee, $inviter);

    expect($recorded['topic'])->toBe(OutboxTopic::UserInvitationRequested->value)
        ->and($recorded['tenantId'])->toBe((string) $this->tenant->getKey())
        ->and($recorded['subject']->id)->toBe((string) $this->invitee->getKey())
        ->and(array_keys($recorded['payload']))->toBe(['inviter_name', 'invitation_key'])
        ->and($this->invitee->invitation_token_hash)->toBe('old-hash')
        ->and($this->invitee->isDirty())->toBeFalse();
});
