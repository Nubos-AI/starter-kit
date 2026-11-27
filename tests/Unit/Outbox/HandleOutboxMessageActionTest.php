<?php

declare(strict_types=1);

use App\Actions\Outbox\HandleOutboxMessageAction;
use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSubject;
use App\Support\Modules\ModuleAccess;
use App\Support\Outbox\OutboxHandlerRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->handler = new class implements IdempotentOutboxHandler
    {
        /** @var list<string> */
        public array $handledTenantIds = [];

        public function topic(): string
        {
            return 'module.event';
        }

        public function handle(OutboxMessage $message): void
        {
            $this->handledTenantIds[] = $message->tenantId;
        }
    };

    $this->message = new OutboxMessage('module.event', 'tenant-without-module', new OutboxSubject('subject', 'subject-id'), [], 1);

    $this->access = Mockery::mock(ModuleAccess::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('never runs a handler for a tenant whose module access is denied and runs it once access is granted', function (): void {
    $this->access->shouldReceive('allowsForTenantId')->with($this->handler, 'tenant-without-module')->andReturnFalse();
    $this->access->shouldReceive('allowsForTenantId')->with($this->handler, 'tenant-with-module')->andReturnTrue();

    $action = new HandleOutboxMessageAction(new OutboxHandlerRegistry([$this->handler], $this->access));

    $action->execute($this->handler::class, $this->message);

    expect($this->handler->handledTenantIds)->toBe([]);

    $action->execute($this->handler::class, new OutboxMessage('module.event', 'tenant-with-module', new OutboxSubject('subject', 'subject-id'), [], 1));

    expect($this->handler->handledTenantIds)->toBe(['tenant-with-module']);
});
