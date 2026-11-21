<?php

declare(strict_types=1);

use App\Models\ExportFieldPreset;
use App\Models\ImportMappingPreset;
use App\Policies\Export\ExportFieldPresetPolicy;
use App\Policies\Import\ImportMappingPresetPolicy;
use App\Support\Authorization\TenantBoundary;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

dataset('creator or admin preset policies', [
    'import mapping preset' => [ImportMappingPresetPolicy::class, ImportMappingPreset::class],
    'export field preset' => [ExportFieldPresetPolicy::class, ExportFieldPreset::class],
]);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    /** @var callable(string, bool, ?string):StaticAuthorityUser */
    $this->userOf = function (string $seed, bool $escalated = false, ?string $tenantId = null): StaticAuthorityUser {
        /** @var StaticAuthorityUser $user */
        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);
        $user->escalated = $escalated;

        return $user;
    };

    /** @var callable(class-string<Model>, string, ?string):Model */
    $this->presetOf = fn (string $presetClass, string $ownerId, ?string $tenantId = null): Model => ModelStub::make($presetClass, [
        'id' => ModelStub::ulid('preset'),
        'tenant_id' => $tenantId ?? $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'user_id' => $ownerId,
    ]);

    /** @var callable(class-string<ImportMappingPresetPolicy|ExportFieldPresetPolicy>):(ImportMappingPresetPolicy|ExportFieldPresetPolicy) */
    $this->policyOf = static fn (string $policyClass): ImportMappingPresetPolicy|ExportFieldPresetPolicy => new $policyClass(new TenantBoundary);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets the creator change and remove their own preset', function (string $policyClass, string $presetClass): void {
    $policy = ($this->policyOf)($policyClass);
    $creator = ($this->userOf)('creator');
    $preset = ($this->presetOf)($presetClass, (string) $creator->getKey());

    expect($policy->update($creator, $preset))->toBeTrue()
        ->and($policy->delete($creator, $preset))->toBeTrue();
})->with('creator or admin preset policies');

it('refuses a colleague of the same tenant who did not create the preset', function (string $policyClass, string $presetClass): void {
    $policy = ($this->policyOf)($policyClass);
    $preset = ($this->presetOf)($presetClass, (string) ($this->userOf)('creator')->getKey());
    $colleague = ($this->userOf)('colleague');

    expect($policy->update($colleague, $preset))->toBeFalse()
        ->and($policy->delete($colleague, $preset))->toBeFalse();
})->with('creator or admin preset policies');

it('lets an escalated authority of the same tenant change a foreign preset', function (string $policyClass, string $presetClass): void {
    $policy = ($this->policyOf)($policyClass);
    $preset = ($this->presetOf)($presetClass, (string) ($this->userOf)('creator')->getKey());
    $admin = ($this->userOf)('admin', true);

    expect($policy->update($admin, $preset))->toBeTrue()
        ->and($policy->delete($admin, $preset))->toBeTrue();
})->with('creator or admin preset policies');

it('refuses even the creator once the preset belongs to another tenant', function (string $policyClass, string $presetClass): void {
    $policy = ($this->policyOf)($policyClass);
    $creator = ($this->userOf)('creator');
    $preset = ($this->presetOf)($presetClass, (string) $creator->getKey(), ModelStub::ulid('other-tenant'));

    expect($policy->update($creator, $preset))->toBeFalse()
        ->and($policy->delete($creator, $preset))->toBeFalse();
})->with('creator or admin preset policies');

it('refuses an escalated authority from another tenant', function (string $policyClass, string $presetClass): void {
    $policy = ($this->policyOf)($policyClass);
    $preset = ($this->presetOf)($presetClass, (string) ($this->userOf)('creator')->getKey());
    $foreignAdmin = ($this->userOf)('foreign-admin', true, ModelStub::ulid('other-tenant'));

    expect($policy->update($foreignAdmin, $preset))->toBeFalse()
        ->and($policy->delete($foreignAdmin, $preset))->toBeFalse();
})->with('creator or admin preset policies');
