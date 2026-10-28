<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\NotificationSettingsController;
use App\Models\User;
use App\Support\Notifications\NotificationTypeCatalog;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['notifications.types' => (require config_path('notifications.php'))['types']]);

    $this->catalog = app(NotificationTypeCatalog::class);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('offers the notification types the core sends from its configuration', function (): void {
    expect($this->catalog->keys())->toBe([
        'reminder.due',
        'rule.triggered',
        'import.completed',
        'export.completed',
        'bulk.grouped',
        'approval.requested',
        'approval.decided',
        'approval.escalated',
    ]);
});

it('hands every type out with its translated label', function (): void {
    expect($this->catalog->options()[0])->toBe(['key' => 'reminder.due', 'label' => 'Erinnerung fällig']);
});

it('lists a type a package appends to the configuration', function (): void {
    config(['notifications.types' => [...config('notifications.types'), ['key' => 'package.thing_happened', 'label' => 'Paket-Ereignis']]]);

    expect($this->catalog->keys())->toContain('package.thing_happened')
        ->and($this->catalog->options())->toContain(['key' => 'package.thing_happened', 'label' => 'Paket-Ereignis']);
});

it('keeps a type registered twice only once with the label registered last', function (): void {
    config(['notifications.types' => [
        ['key' => 'package.thing_happened', 'label' => 'Erste Bezeichnung'],
        ['key' => 'package.thing_happened', 'label' => 'Zweite Bezeichnung'],
    ]]);

    expect($this->catalog->options())->toBe([['key' => 'package.thing_happened', 'label' => 'Zweite Bezeichnung']]);
});

it('ignores an entry without a key or a label', function (): void {
    config(['notifications.types' => [
        ['key' => 'package.thing_happened'],
        ['label' => 'Ohne Schlüssel'],
        'package.loose_string',
        ['key' => '', 'label' => 'Leerer Schlüssel'],
    ]]);

    expect($this->catalog->keys())->toBe([]);
});

it('renders the settings page with the types from the catalog', function (): void {
    StaticQueryConnection::install(static fn (): array => []);
    config(['notifications.types' => [...config('notifications.types'), ['key' => 'package.thing_happened', 'label' => 'Paket-Ereignis']]]);

    $tenant = AccessContext::tenant();
    $user = AccessContext::user($tenant);
    $request = Request::create('/settings/notifications', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
    app()->instance('request', $request);
    $request->setUserResolver(static fn (): User => $user);

    $response = app(NotificationSettingsController::class)->edit($request)->toResponse($request);
    $types = $response->getData(true)['props']['types'];

    expect($types)->toHaveCount(9)
        ->and($types[0])->toBe(['key' => 'reminder.due', 'label' => 'Erinnerung fällig'])
        ->and($types[8])->toBe(['key' => 'package.thing_happened', 'label' => 'Paket-Ereignis']);
});
