<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\NotificationRulePageController;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->ruleId = ModelStub::ulid('due-rule');

    StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "notification_rules"') => [[
                'id' => $this->ruleId,
                'tenant_id' => $this->tenant->getKey(),
                'object_type_id' => $this->objectTypeId,
                'name' => 'Follow-up due',
                'trigger_type' => 'date_based',
                'config' => null,
                'segment_id' => null,
                'filter_definition' => null,
                'action' => null,
                'is_active' => false,
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->page = function (): array {
        $request = Request::create('/probe', 'GET');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->user);
        app()->instance('request', $request);

        $objectType = ModelStub::make(ObjectType::class, [
            'id' => $this->objectTypeId,
            'tenant_id' => $this->tenant->getKey(),
            'key' => 'companies',
            'slug' => 'companies',
            'name' => 'Firmen',
        ]);

        return app(NotificationRulePageController::class)
            ->index($objectType)
            ->toResponse($request)
            ->original
            ->getData()['page'];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('guards the notification rule page with the rule management permission of the object type', function (): void {
    expect(RouteShape::named('notification-rules.edit')->hasDeclaredMiddleware('permission:{objectType}.rules.manage'))->toBeTrue();
});

it('renders the rules as an object type list page outside the personal settings shell', function (): void {
    $page = ($this->page)();

    expect($page['component'])->toBe('notificationRules/Index')
        ->and($page['component'])->not->toStartWith('settings/')
        ->and($page['props']['objectType']['slug'])->toBe('companies')
        ->and($page['props']['rules'])->toHaveCount(1)
        ->and($page['props']['rules'][0]['id'])->toBe($this->ruleId)
        ->and($page['props']['rules'][0]['trigger_type'])->toBe('date_based')
        ->and($page['props']['rules'][0]['is_active'])->toBeFalse();
});
