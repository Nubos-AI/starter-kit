<?php

declare(strict_types=1);

use App\Actions\Activities\DeleteRecordActivityAction;
use App\Actions\Activities\SaveRecordActivityAction;
use App\Http\Controllers\Activities\ActivitiesController;
use App\Models\RecordActivity;
use App\Models\User;
use App\Support\Links\SourceLinkCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('activities-controller-tenant');
    $this->user = AccessContext::user($this->tenant, [], 'activities-controller-user');
    $this->mailId = ModelStub::ulid('activities-controller-mail');

    $this->activity = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('activities-controller-activity'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => null,
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        'subject' => 'Angebot besprochen',
        'occurred_at' => '2026-10-01 09:00:00',
    ]);

    $this->link = ['key' => 'email_message', 'id' => $this->mailId, 'label' => 'E-Mail: Angebot', 'href' => '/mails/1'];

    $this->save = Mockery::mock(SaveRecordActivityAction::class);
    $this->delete = Mockery::mock(DeleteRecordActivityAction::class);
    $this->catalog = Mockery::mock(SourceLinkCatalog::class);
    $this->controller = new ActivitiesController($this->catalog);

    $this->payload = [
        'subject' => 'Angebot besprochen',
        'occurred_at' => '2026-10-01T09:00:00Z',
        'activity_type_id' => ModelStub::ulid('activities-controller-type'),
        'assignee_id' => (string) $this->user->getKey(),
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
    ];

    $this->request = function (array $payload = []): Request {
        $request = Request::create('/activities', 'POST', $payload);
        $user = $this->user;
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    Mockery::close();
    AccessContext::forgetTenant();
});

it('asks the policy before it hands a standalone activity to the action', function (): void {
    $gate = GateSpy::allowing();
    $this->save->shouldNotReceive('execute');

    expect(fn (): mixed => $this->controller->store(($this->request)($this->payload), $this->save))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('create')
        ->and($gate->calls[0]['arguments'][0])->toBe(RecordActivity::class);
});

it('creates a standalone activity from exactly the posted fields and answers with its quick links', function (): void {
    GateSpy::allowing('create');

    $this->save->shouldReceive('execute')
        ->once()
        ->withArgs(fn (User $actor, mixed $record, array $input): bool => $actor === $this->user && $record === null && $input === $this->payload)
        ->andReturn($this->activity);
    $this->catalog->shouldReceive('linksOf')->once()->with($this->user, $this->activity)->andReturn([$this->link]);

    $response = $this->controller->store(($this->request)($this->payload), $this->save);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['data']['links'])->toBe([$this->link])
        ->and($response->getData(true)['data']['sourceId'])->toBe($this->mailId);
});

it('refuses to change or delete an activity the policy denies and never reaches the action', function (): void {
    $gate = GateSpy::allowing();
    $this->save->shouldNotReceive('execute');
    $this->delete->shouldNotReceive('execute');

    expect(fn (): mixed => $this->controller->update(($this->request)($this->payload), $this->activity, $this->save))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): mixed => $this->controller->destroy($this->activity, $this->delete))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['update', 'delete']);
});

it('leaves a record bound activity to the record endpoints', function (): void {
    GateSpy::allowing('update', 'delete');
    $bound = ModelStub::make(RecordActivity::class, ['id' => ModelStub::ulid('activities-controller-bound'), 'record_id' => ModelStub::ulid('activities-controller-record')]);
    $this->save->shouldNotReceive('execute');
    $this->delete->shouldNotReceive('execute');

    expect(fn (): mixed => $this->controller->update(($this->request)($this->payload), $bound, $this->save))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn (): mixed => $this->controller->destroy($bound, $this->delete))
        ->toThrow(NotFoundHttpException::class);
});

it('deletes a standalone activity the policy allows', function (): void {
    GateSpy::allowing('delete');
    $this->delete->shouldReceive('execute')->once()->with($this->activity);

    expect($this->controller->destroy($this->activity, $this->delete)->getStatusCode())->toBe(204);
});

it('registers the standalone activity routes on ULID keys', function (): void {
    expect(RouteShape::named('activities.store')->handledBy())->toBe(ActivitiesController::class.'@store')
        ->and(RouteShape::named('activities.store')->methods())->toContain('POST')
        ->and(RouteShape::named('activities.update')->constraints())->toHaveKey('activity')
        ->and(RouteShape::named('activities.update')->methods())->toContain('PUT')
        ->and(RouteShape::named('activities.destroy')->constraints())->toHaveKey('activity')
        ->and(RouteShape::named('activities.destroy')->methods())->toContain('DELETE');

    foreach (['activities.update', 'activities.destroy'] as $name) {
        $pattern = (string) (RouteShape::named($name)->constraints()['activity'] ?? '');

        expect(preg_match("#^(?:{$pattern})$#", '01JBRT0000000000000000000A'))->toBe(1)
            ->and(preg_match("#^(?:{$pattern})$#", '550e8400-e29b-41d4-a716-446655440000'))->toBe(0)
            ->and(preg_match("#^(?:{$pattern})$#", '42'))->toBe(0)
            ->and(preg_match("#^(?:{$pattern})$#", '..%2F01JBRT0000000000000000000A'))->toBe(0);
    }
});
