<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnsurePermission;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
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
        'slug' => 'companies',
    ]);

    /** @var callable(?User, array<string, mixed>):Request */
    $this->requestFor = function (?User $user, array $parameters = []): Request {
        $request = Request::create('/probe', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('GET', 'probe', []);
        $route->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a literal ability through only when the actor holds the permission', function (string $granted, bool $isReached): void {
    $resolver = AccessContext::grant($granted);

    $request = ($this->requestFor)(AccessContext::user($this->tenant));

    if ($isReached) {
        expect((new EnsurePermission)->handle($request, $this->reached, 'roles.view')->getContent())->toBe('reached');

        return;
    }

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, 'roles.view'))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['roles.view']);
})->with([
    'the actor holds the permission' => ['roles.view', true],
    'the actor does not hold the permission' => ['roles.create', false],
]);

it('rejects a placeholder ability the actor holds only for another object type', function (): void {
    AccessContext::grant('contacts.view');

    $request = ($this->requestFor)(AccessContext::user($this->tenant), ['objectType' => $this->objectType]);

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, '{objectType}.view'))
        ->toThrow(AuthorizationException::class);
});
