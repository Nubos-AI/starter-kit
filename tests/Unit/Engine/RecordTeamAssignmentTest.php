<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Models\CustomRecord;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('team-assignment-companies');
    $this->foreignTeamId = ModelStub::ulid('foreign-team');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to create a record for a team outside the tenant and looks the team up within the tenant', function (): void {
    $presence = FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $errors = [];

    try {
        app(CreateRecordAction::class)->execute([
            'object_type_id' => $this->objectTypeId,
            'team_id' => $this->foreignTeamId,
        ]);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKey('team_id')
        ->and($presence->wasLookedUpWithin('teams', $this->tenantId))->toBeTrue();
});

it('refuses to move a record to a team outside the tenant the same way creation does', function (): void {
    $presence = FakePresenceVerifier::holding([]);

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('team-assignment-record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'version' => 1,
    ]);

    expect(fn (): mixed => app(UpdateRecordAction::class)->execute($record, ['version' => 1, 'team_id' => $this->foreignTeamId]))
        ->toThrow(ValidationException::class)
        ->and($presence->wasLookedUpWithin('teams', $this->tenantId))->toBeTrue();
});
