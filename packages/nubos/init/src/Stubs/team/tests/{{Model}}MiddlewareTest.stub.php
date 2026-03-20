<?php

declare(strict_types=1);

use App\Actions\{{Models}}\Create{{Model}}Action;
use App\Http\Middleware\RedirectToCurrent{{Model}};
use App\Http\Middleware\SetCurrent{{Model}};
use App\Models\{{Model}};
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $action = new Create{{Model}}Action();
    $this->{{model}} = $action->execute($this->user, ['name' => 'Test {{Model}}']);
});

describe('SetCurrent{{Model}}', function (): void {
    it('sets current {{model}} from route parameter', function (): void {
        $this->actingAs($this->user)
            ->get('/{{models}}/' . $this->{{model}}->slug . '/dashboard')
            ->assertOk();
    });

    it('returns 404 for non-existent {{model}}', function (): void {
        $this->actingAs($this->user)
            ->get('/{{models}}/non-existent/dashboard')
            ->assertNotFound();
    });

    it('returns 403 for non-member', function (): void {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get('/{{models}}/' . $this->{{model}}->slug . '/dashboard')
            ->assertForbidden();
    });
});

describe('RedirectToCurrent{{Model}}', function (): void {
    it('redirects to current {{model}}', function (): void {
        $middleware = new RedirectToCurrent{{Model}}();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->user);

        $response = $middleware->handle($request, fn () => new Response());

        expect($response->getStatusCode())->toBe(302);
    });
});
