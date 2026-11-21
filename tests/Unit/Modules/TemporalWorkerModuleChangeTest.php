<?php

declare(strict_types=1);

use App\Console\Commands\WorkTemporalCommand;
use App\Support\Modules\ModuleStateFingerprint;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->workerSeeing = function (array $states): WorkTemporalCommand {
        $fingerprint = new class($states) extends ModuleStateFingerprint
        {
            /** @param list<string|null> $states */
            public function __construct(private array $states) {}

            public function current(): ?string
            {
                return array_shift($this->states);
            }
        };

        $command = new class($fingerprint) extends WorkTemporalCommand
        {
            public function observe(Process $server): bool
            {
                $this->server = $server;

                return $this->stopOnModuleChange();
            }
        };
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return $command;
    };

    $this->stops = new ArrayObject;
    $this->server = function (): Process {
        $server = Mockery::mock(Process::class);
        $server->shouldReceive('stop')->andReturnUsing(function (): int {
            $this->stops[] = 'stop';

            return 0;
        });

        return $server;
    };
});

afterEach(function (): void {
    Mockery::close();
});

it('replaces the temporal worker command of the package so the worker container picks up module changes', function (): void {
    expect(Artisan::all()['temporal:work'])->toBeInstanceOf(WorkTemporalCommand::class);
});

it('stops the worker once the installed or registered modules change so the container restarts it with fresh code and schedules', function (): void {
    $command = ($this->workerSeeing)(['modules-a', 'modules-a', 'modules-b']);
    $server = ($this->server)();

    expect([$command->observe($server), $command->observe($server), $command->observe($server)])->toBe([false, false, true])
        ->and($this->stops->getArrayCopy())->toBe(['stop']);
});

it('keeps the worker running while the module state is unchanged or cannot be read', function (): void {
    $command = ($this->workerSeeing)(['modules-a', null, 'modules-a']);
    $server = ($this->server)();

    expect([$command->observe($server), $command->observe($server), $command->observe($server)])->toBe([false, false, false])
        ->and($this->stops->getArrayCopy())->toBe([]);
});

it('reports no module state and logs the reason when the module registry cannot be read', function (): void {
    Log::spy();

    expect((new ModuleStateFingerprint(app(ConnectionResolverInterface::class)))->current())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});
