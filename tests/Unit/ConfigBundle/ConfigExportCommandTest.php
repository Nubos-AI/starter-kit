<?php

declare(strict_types=1);

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Console\Commands\ExportConfigBundle;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\User;
use App\Support\Console\ActingUserResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Log::spy();

    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, ['email' => 'operator@example.test']);

    $this->root = sys_get_temp_dir().'/nubos-config-export-'.bin2hex(random_bytes(6));

    $this->bindPassThroughContext = function (): void {
        $context = Mockery::mock(ActingUserContext::class);
        $context->shouldReceive('run')
            ->andReturnUsing(fn (string $tenantId, string $actingUserId, Closure $callback): mixed => $callback($this->actor));

        app()->instance(ActingUserContext::class, $context);
    };

    $this->bindResolverReturning = function (User $user): void {
        $resolver = Mockery::mock(ActingUserResolver::class);
        $resolver->shouldReceive('resolve')->andReturnUsing(static function (?string $identifier) use ($user): User {
            if ($identifier === null || $identifier === '') {
                throw new MissingActingUserException('Die Option --as-user ist erforderlich.', 'acting-user-missing');
            }

            return $user;
        });

        app()->instance(ActingUserResolver::class, $resolver);
    };

    /** @var callable(?string, ?Throwable):object */
    $this->bindExportAction = static function (?string $refusal, ?Throwable $failure = null): object {
        $state = new stdClass;
        $state->refusalCalls = 0;
        $state->entryPoints = [];

        $action = Mockery::mock(ExportConfigBundleAction::class);

        $action->shouldReceive('refusalFor')->andReturnUsing(static function () use ($state, $refusal): ?string {
            $state->refusalCalls++;

            return $refusal;
        });

        $action->shouldReceive('execute')->andReturnUsing(static function (User $actor, ConfigExportEntryPoint $entryPoint) use ($state, $failure): ConfigBundle {
            $state->entryPoints[] = $entryPoint->value;

            if ($failure !== null) {
                throw $failure;
            }

            return new ConfigBundle(
                new BundleManifest(4, 'Produktion', [ArtifactKind::ObjectTypes->value => 1]),
                [new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['key' => 'deal', 'name' => 'Deal'])],
            );
        });

        app()->instance(ExportConfigBundleAction::class, $action);

        return $state;
    };

    /** @var callable(array<string, string>):array{exit: int, output: string} */
    $this->runCommand = static function (array $options): array {
        $command = new ExportConfigBundle;
        $command->setLaravel(app());

        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

        return ['exit' => $exitCode, 'output' => $output->fetch()];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('declares exactly the acting user and path options on config:export', function (): void {
    $command = new ExportConfigBundle;

    expect($command->getName())->toBe('config:export')
        ->and(array_keys($command->getDefinition()->getOptions()))->toEqualCanonicalizing(['as-user', 'path']);
});

it('writes the serialised tree of the acting users tenant into an empty target directory and reports file count and path', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    $action = ($this->bindExportAction)(null);

    $directory = "{$this->root}/export";

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
        '--path' => $directory,
    ]);

    $relatives = array_map(
        static fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()),
        File::allFiles($directory),
    );
    sort($relatives);

    $outputWithoutPath = str_replace($directory, '', $output);

    expect($exitCode)->toBe(0, $output)
        ->and($action->entryPoints)->toBe([ConfigExportEntryPoint::Console->value])
        ->and($relatives)->toBe(['manifest.yaml', 'object-types/deal.yaml'])
        ->and($output)->toContain($directory)
        ->and(preg_match('/\b2\b/', $outputWithoutPath))->toBe(1);
});

it('refuses an export without --as-user, writes no file and never asks the export action', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    $action = ($this->bindExportAction)(null);

    $directory = "{$this->root}/export";

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(['--path' => $directory]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('--as-user')
        ->and($output)->toContain('erforderlich')
        ->and(File::exists($directory))->toBeFalse()
        ->and($action->refusalCalls)->toBe(0)
        ->and($action->entryPoints)->toBe([]);
});

it('refuses an actor the export action turns down, writes nothing and never serialises', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    $action = ($this->bindExportAction)('Ihnen fehlt die Berechtigung config.export.');

    $directory = "{$this->root}/export";

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
        '--path' => $directory,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('config.export')
        ->and(File::exists($directory))->toBeFalse()
        ->and($action->refusalCalls)->toBe(1)
        ->and($action->entryPoints)->toBe([]);
});

it('checks the permission before the target and names the missing permission instead of the target', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    ($this->bindExportAction)('Ihnen fehlt die Berechtigung config.export.');

    $directory = "{$this->root}/export";
    $staleContent = "key: stale\n";

    File::ensureDirectoryExists($directory);
    File::put("{$directory}/stale.yaml", $staleContent);

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
        '--path' => $directory,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('config.export')
        ->and($output)->not->toContain('Zielverzeichnis')
        ->and(File::get("{$directory}/stale.yaml"))->toBe($staleContent)
        ->and(File::allFiles($directory))->toHaveCount(1);
});

it('refuses a target that is a file or a non empty directory and leaves the existing content untouched', function (string $variant): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    $action = ($this->bindExportAction)(null);

    $directory = "{$this->root}/export";
    $stalePath = $variant === 'file' ? $directory : "{$directory}/stale.yaml";
    $staleContent = "key: stale\n";

    File::ensureDirectoryExists(dirname($stalePath));
    File::put($stalePath, $staleContent);

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
        '--path' => $directory,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(trim($output))->not->toBe('')
        ->and(File::get($stalePath))->toBe($staleContent)
        ->and($action->entryPoints)->toBe([]);

    if ($variant === 'file') {
        expect(File::isFile($directory))->toBeTrue();
    } else {
        expect(File::allFiles($directory))->toHaveCount(1);
    }
})->with([
    'a plain file' => ['file'],
    'a directory that already holds a file' => ['directory'],
]);

it('refuses an export without a path at all', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    $action = ($this->bindExportAction)(null);

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(trim($output))->not->toBe('')
        ->and($action->entryPoints)->toBe([]);
});

it('writes no export directory when the export action fails', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindPassThroughContext)();
    ($this->bindExportAction)(null, new RuntimeException('audit down'));

    $directory = "{$this->root}/export";

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--as-user' => (string) $this->actor->email,
        '--path' => $directory,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('audit down')
        ->and(File::exists($directory))->toBeFalse();
});
