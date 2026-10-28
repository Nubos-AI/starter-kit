<?php

declare(strict_types=1);

use App\Models\ExportFieldPreset;
use App\Policies\Export\ExportFieldPresetPolicy;
use App\Support\Authorization\TenantBoundary;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->policy = new ExportFieldPresetPolicy(new TenantBoundary);

    /** @var callable(string, bool, string):StaticAuthorityUser */
    $this->userOf = function (string $seed, bool $escalated = false, ?string $tenantId = null): StaticAuthorityUser {
        /** @var StaticAuthorityUser $user */
        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);
        $user->escalated = $escalated;

        return $user;
    };

    /** @var callable(string, ?string):ExportFieldPreset */
    $this->presetOf = fn (string $ownerId, ?string $tenantId = null): ExportFieldPreset => ModelStub::make(ExportFieldPreset::class, [
        'id' => ModelStub::ulid('preset'),
        'tenant_id' => $tenantId ?? $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'user_id' => $ownerId,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets the creator change and remove their own field preset', function (): void {
    $creator = ($this->userOf)('creator');
    $preset = ($this->presetOf)((string) $creator->getKey());

    expect($this->policy->update($creator, $preset))->toBeTrue()
        ->and($this->policy->delete($creator, $preset))->toBeTrue();
});

it('refuses a colleague of the same tenant who did not create the field preset', function (): void {
    $preset = ($this->presetOf)((string) ($this->userOf)('creator')->getKey());
    $colleague = ($this->userOf)('colleague');

    expect($this->policy->update($colleague, $preset))->toBeFalse()
        ->and($this->policy->delete($colleague, $preset))->toBeFalse();
});

it('lets an escalated authority of the same tenant change a foreign field preset', function (): void {
    $preset = ($this->presetOf)((string) ($this->userOf)('creator')->getKey());
    $admin = ($this->userOf)('admin', true);

    expect($this->policy->update($admin, $preset))->toBeTrue()
        ->and($this->policy->delete($admin, $preset))->toBeTrue();
});

it('refuses even the creator once the preset belongs to another tenant', function (): void {
    $creator = ($this->userOf)('creator');
    $preset = ($this->presetOf)((string) $creator->getKey(), ModelStub::ulid('other-tenant'));

    expect($this->policy->update($creator, $preset))->toBeFalse()
        ->and($this->policy->delete($creator, $preset))->toBeFalse();
});

it('refuses an escalated authority from another tenant', function (): void {
    $preset = ($this->presetOf)((string) ($this->userOf)('creator')->getKey());
    $foreignAdmin = ($this->userOf)('foreign-admin', true, ModelStub::ulid('other-tenant'));

    expect($this->policy->update($foreignAdmin, $preset))->toBeFalse()
        ->and($this->policy->delete($foreignAdmin, $preset))->toBeFalse();
});
