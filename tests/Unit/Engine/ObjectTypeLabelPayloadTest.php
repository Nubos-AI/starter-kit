<?php

declare(strict_types=1);

use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\Http\Controllers\Engine\ObjectTypesController;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Engine\ObjectTypePresenter;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'companies',
        'slug' => 'companies',
        'name' => 'Companies',
        'is_system' => false,
        'nav_icon' => 'database',
        'storage_strategy' => 'generic',
        'i18n_labels' => ['de' => 'Firmen', 'en' => 'Accounts'],
    ]);
});

afterEach(function (): void {
    app()->setLocale('de');
    AccessContext::forgetTenant();
});

it('hands the record pages the translated label next to the technical name', function (): void {
    app()->setLocale('de');

    expect((new ObjectTypePresenter)->summary($this->objectType))
        ->toMatchArray(['name' => 'Companies', 'label' => 'Firmen']);
});

it('hands the details form the label of the active locale alone so the form never edits a fallback', function (): void {
    $backing = Mockery::mock(ObjectTypeBackingInterface::class);
    $backing->shouldReceive('hasRows')->andReturnFalse();
    $registry = Mockery::mock(ObjectTypeBackingRegistry::class);
    $registry->shouldReceive('for')->andReturn($backing);
    app()->instance(ObjectTypeBackingRegistry::class, $registry);

    $request = Request::create('/', 'GET');
    $request->headers->set('X-Inertia', 'true');

    $propsIn = function (string $locale) use ($request): array {
        app()->setLocale($locale);

        return app(ObjectTypesController::class)->edit($this->objectType)->toResponse($request)->getData(true)['props']['objectType'];
    };

    expect($propsIn('de'))->toMatchArray(['name' => 'Companies', 'label' => 'Firmen', 'locale_label' => 'Firmen'])
        ->and($propsIn('fr'))->toMatchArray(['name' => 'Companies', 'label' => 'Accounts', 'locale_label' => '']);
});
