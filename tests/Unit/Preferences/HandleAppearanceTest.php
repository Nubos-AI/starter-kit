<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Models\User;
use App\Support\Preferences\PreferencePolicyResolver;
use App\Support\Preferences\PreferenceSchema;
use App\Support\Preferences\UserPreferenceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);

    /** @var callable(array<string, mixed>):HandleAppearance */
    $this->middlewareOver = static function (array $stored): HandleAppearance {
        $preferences = new class(new PreferenceSchema, new PreferencePolicyResolver, $stored) extends UserPreferenceResolver
        {
            /**
             * @param  array<string, mixed>  $rows
             */
            public function __construct(
                PreferenceSchema $schema,
                PreferencePolicyResolver $policies,
                private readonly array $rows,
            ) {
                parent::__construct($schema, $policies);
            }

            /**
             * @return array<string, mixed>
             */
            public function stored(User $user): array
            {
                return $this->rows;
            }
        };

        return new HandleAppearance($preferences);
    };

    /** @var callable(HandleAppearance, ?User, ?string):string */
    $this->appearanceFor = static function (HandleAppearance $middleware, ?User $user, ?string $cookie): string {
        $request = Request::create('/dashboard');
        $request->setUserResolver(static fn (): ?User => $user);

        if ($cookie !== null) {
            $request->cookies->set('appearance', $cookie);
        }

        $middleware->handle($request, static fn (): Response => new Response);

        return (string) View::shared('appearance');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets the stored appearance win over the cookie the browser sends', function (): void {
    $middleware = ($this->middlewareOver)(['settings' => ['appearance' => 'dark']]);

    expect(($this->appearanceFor)($middleware, $this->user, 'light'))->toBe('dark');
});

it('falls back to the cookie for a guest', function (): void {
    $middleware = ($this->middlewareOver)([]);

    expect(($this->appearanceFor)($middleware, null, 'light'))->toBe('light');
});

it('falls back to the cookie for a signed in user who stored no appearance', function (): void {
    $middleware = ($this->middlewareOver)(['settings' => ['density' => 'comfortable']]);

    expect(($this->appearanceFor)($middleware, $this->user, 'light'))->toBe('light');
});

it('settles on the system appearance when neither a stored value nor a cookie exists', function (): void {
    $middleware = ($this->middlewareOver)([]);

    expect(($this->appearanceFor)($middleware, $this->user, null))->toBe('system');
});

it('ignores an empty stored appearance instead of blanking the first paint', function (): void {
    $middleware = ($this->middlewareOver)(['settings' => ['appearance' => '']]);

    expect(($this->appearanceFor)($middleware, $this->user, 'light'))->toBe('light');
});
