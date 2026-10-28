<?php

declare(strict_types=1);

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Actions\ConfigBundle\ImportConfigBundleAction;
use App\Actions\ConfigBundle\PrepareConfigImportAction;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Models\PromotionRun;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('config-bundle-tenant');

    $this->holderWith = fn (bool $escalated, string $seed): User => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $escalated
            ? [ModelStub::make(Role::class, [
                'id' => ModelStub::ulid('config-bundle-role'),
                'tenant_id' => $this->tenant->getKey(),
                'authority' => RoleAuthority::SuperAdmin->value,
            ])]
            : [],
        $seed,
    );

    $this->prepare = fn (): PrepareConfigImportAction => app(PrepareConfigImportAction::class);
    $this->export = fn (): ExportConfigBundleAction => app(ExportConfigBundleAction::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to prepare an import without the config import permission', function (): void {
    AccessContext::grant();
    $actor = ($this->holderWith)(false, 'config-bundle-operator');

    $refusal = ($this->prepare)()->refusalFor($actor);

    expect($refusal)->toContain('config.import')
        ->and(fn (): PromotionRun => ($this->prepare)()->execute($actor, '/tmp/bundle.zip'))
        ->toThrow(AuthorizationException::class);
});

it('refuses to prepare an import to a permitted actor without escalated authority', function (): void {
    AccessContext::grant('config.import');
    $actor = ($this->holderWith)(false, 'config-bundle-operator');

    $refusal = ($this->prepare)()->refusalFor($actor);

    expect($refusal)->not->toBeNull()
        ->and($refusal)->not->toContain('config.import')
        ->and(fn (): PromotionRun => ($this->prepare)()->execute($actor, '/tmp/bundle.zip'))
        ->toThrow(AuthorizationException::class);
});

it('lets an escalated authority prepare an import', function (): void {
    AccessContext::grant();

    expect(($this->prepare)()->refusalFor(($this->holderWith)(true, 'config-bundle-admin')))->toBeNull();
});

it('refuses an export without the config export permission', function (): void {
    AccessContext::grant();
    $actor = ($this->holderWith)(false, 'config-bundle-operator');

    expect(($this->export)()->refusalFor($actor))->toContain('config.export')
        ->and(fn (): ConfigBundle => ($this->export)()->execute($actor, ConfigExportEntryPoint::Console))
        ->toThrow(AuthorizationException::class);
});

it('asks for no escalated authority on an export, only for the export permission', function (): void {
    AccessContext::grant('config.export');

    expect(($this->export)()->refusalFor(($this->holderWith)(false, 'config-bundle-operator')))->toBeNull();
});

it('demands both the permission and the escalated authority for the writing import', function (): void {
    AccessContext::grant('config.import');
    $permitted = ($this->holderWith)(false, 'config-bundle-operator');

    $import = new ReflectionMethod(ImportConfigBundleAction::class, 'import');

    expect(fn (): PromotionRun => $import->invoke(
        app(ImportConfigBundleAction::class),
        $permitted,
        '/tmp/bundle',
        'release',
    ))->toThrow(AuthorizationException::class);
});

it('refuses the writing import to an actor without the config import permission at all', function (): void {
    AccessContext::grant();
    $stranger = ($this->holderWith)(false, 'config-bundle-stranger');

    $import = new ReflectionMethod(ImportConfigBundleAction::class, 'import');

    expect(fn (): PromotionRun => $import->invoke(
        app(ImportConfigBundleAction::class),
        $stranger,
        '/tmp/bundle',
        'release',
    ))->toThrow(AuthorizationException::class);
});
