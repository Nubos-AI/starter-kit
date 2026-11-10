<?php

declare(strict_types=1);

use App\Actions\ConfigBundle\ImportConfigBundleAction;
use App\Console\Commands\ImportConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Console\ActingUserResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Log::spy();

    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, ['email' => 'operator@example.test']);

    $this->label = 'ci-pipeline';
    $this->counterpart = 'bundle:staging-eu';
    $this->runId = ModelStub::ulid('promotion-run');

    $this->runWith = fn (array $results): PromotionRun => ModelStub::make(PromotionRun::class, [
        'id' => $this->runId,
        'tenant_id' => $this->tenant->getKey(),
        'counterpart_key' => $this->counterpart,
        'report' => ['results' => $results],
    ]);

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

    $this->bindFailingResolver = static function (Throwable $failure): void {
        $resolver = Mockery::mock(ActingUserResolver::class);
        $resolver->shouldReceive('resolve')->andThrow($failure);

        app()->instance(ActingUserResolver::class, $resolver);
    };

    /** @var callable(PromotionRun|Throwable):object */
    $this->bindImportAction = static function (PromotionRun|Throwable $outcome): object {
        $state = new stdClass;
        $state->calls = [];

        $action = Mockery::mock(ImportConfigBundleAction::class);
        $action->shouldReceive('execute')->andReturnUsing(
            static function (User $actor, string $path, string $label) use ($state, $outcome): PromotionRun {
                $state->calls[] = ['actor' => (string) $actor->getKey(), 'path' => $path, 'label' => $label];

                if ($outcome instanceof Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        app()->instance(ImportConfigBundleAction::class, $action);

        return $state;
    };

    /** @var callable(array<string, string>):array{exit: int, output: string} */
    $this->runCommand = static function (array $options): array {
        $command = new ImportConfigBundle;
        $command->setLaravel(app());

        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

        return ['exit' => $exitCode, 'output' => $output->fetch()];
    };

    $this->commandOptions = fn (array $overrides = []): array => [
        '--as-user' => (string) $this->actor->email,
        '--path' => '/tmp/bundle',
        '--label' => $this->label,
        ...$overrides,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('declares exactly the acting user, path and label options on config:import and no switch that resolves conflicts', function (): void {
    $command = new ImportConfigBundle;

    expect($command->getName())->toBe('config:import')
        ->and(array_keys($command->getDefinition()->getOptions()))->toEqualCanonicalizing(['as-user', 'path', 'label']);
});

it('hands acting user, path and label to the import action unchanged', function (): void {
    ($this->bindResolverReturning)($this->actor);
    $state = ($this->bindImportAction)(($this->runWith)([]));

    ['exit' => $exitCode] = ($this->runCommand)(($this->commandOptions)(['--path' => '/var/bundles/staging']));

    expect($exitCode)->toBe(0)
        ->and($state->calls)->toBe([[
            'actor' => (string) $this->actor->getKey(),
            'path' => '/var/bundles/staging',
            'label' => $this->label,
        ]]);
});

it('prints one german line per written artifact and a closing summary naming run, label and counterpart', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(($this->runWith)([
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'catalog', 'action' => ArtifactWriteAction::Created->value],
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'deal', 'action' => ArtifactWriteAction::Updated->value],
        ['kind' => ArtifactKind::Skills->value, 'key' => 'Negotiation', 'action' => ArtifactWriteAction::Removed->value],
        ['kind' => ArtifactKind::Segments->value, 'key' => 'deal:Open', 'action' => ArtifactWriteAction::Skipped->value],
    ]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('angelegt  '.ArtifactKind::ObjectTypes->identifierFor('catalog'))
        ->and($output)->toContain('geändert  '.ArtifactKind::ObjectTypes->identifierFor('deal'))
        ->and($output)->toContain('entfernt  '.ArtifactKind::Skills->identifierFor('Negotiation'))
        ->and($output)->toContain('übersprungen  '.ArtifactKind::Segments->identifierFor('deal:Open'))
        ->and($output)->toContain($this->runId)
        ->and($output)->toContain($this->label)
        ->and($output)->toContain($this->counterpart)
        ->and($output)->toContain('1 angelegt, 1 geändert, 1 entfernt, 1 übersprungen');
});

it('reports a run without a single result as an import that changed nothing', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(($this->runWith)([]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('0 angelegt, 0 geändert, 0 entfernt, 0 übersprungen');
});

it('skips a result entry that is not fully typed and falls back on an unknown kind', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(($this->runWith)([
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 'catalog', 'action' => ArtifactWriteAction::Created->value],
        ['kind' => 'gibt-es-nicht', 'key' => 'fremd', 'action' => ArtifactWriteAction::Created->value],
        ['kind' => ArtifactKind::ObjectTypes->value, 'key' => 42, 'action' => ArtifactWriteAction::Created->value],
        ['key' => 'ohne-kind', 'action' => ArtifactWriteAction::Created->value],
    ]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('gibt-es-nicht:fremd')
        ->and($output)->not->toContain('ohne-kind')
        ->and($output)->toContain('2 angelegt, 0 geändert, 0 entfernt, 0 übersprungen');
});

it('refuses an import without an acting user and never asks the import action', function (): void {
    ($this->bindResolverReturning)($this->actor);
    $state = ($this->bindImportAction)(($this->runWith)([]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)([
        '--path' => '/tmp/bundle',
        '--label' => $this->label,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('--as-user')
        ->and($state->calls)->toBe([]);
});

it('refuses an acting user the resolver turns down and never asks the import action', function (): void {
    ($this->bindFailingResolver)(new MissingActingUserException('Der unter --as-user angegebene Handelnde ist unbekannt.', 'acting-user-unknown'));
    $state = ($this->bindImportAction)(($this->runWith)([]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('unbekannt')
        ->and($state->calls)->toBe([]);
});

it('aborts on an undecided conflict and names every conflicting key plus the way out', function (): void {
    $conflict = static fn (ArtifactKind $kind, string $key): ArtifactDiff => new ArtifactDiff(
        kind: $kind,
        key: $key,
        state: DiffState::Conflicted,
        sourceHash: 'source',
        targetHash: 'target',
        baselineHash: 'baseline',
        changedPaths: ['payload'],
    );

    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(UndecidedConflictsException::forUndecided([
        $conflict(ArtifactKind::ObjectTypes, 'deal'),
        $conflict(ArtifactKind::Skills, 'Negotiation'),
    ]));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(ArtifactKind::ObjectTypes->identifierFor('deal'))
        ->and($output)->toContain(ArtifactKind::Skills->identifierFor('Negotiation'))
        ->and($output)->toContain('Prüfansicht')
        ->and($output)->not->toContain('angelegt,');
});

it('reports a bundle of a newer schema version as a readable refusal without a stack trace', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(UnsupportedBundleSchemaVersionException::newerThanSupported(99, 4));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('99')
        ->and($output)->toContain('neueren Version')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('#0 ');
});

it('reports any other failure of the import action as a refusal instead of letting it escape', function (): void {
    ($this->bindResolverReturning)($this->actor);
    ($this->bindImportAction)(new RuntimeException('Der Mandant befindet sich in Wartung.'));

    ['exit' => $exitCode, 'output' => $output] = ($this->runCommand)(($this->commandOptions)());

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('Der Mandant befindet sich in Wartung.');
});

it('hands the execution to the existing promotion run path instead of snapshotting, applying or remembering on its own', function (): void {
    $path = base_path('app/Actions/ConfigBundle/ImportConfigBundleAction.php');

    expect(File::exists($path))->toBeTrue('app/Actions/ConfigBundle/ImportConfigBundleAction.php does not exist.');

    $source = File::get($path);

    expect($source)->toContain('ExecutePromotionRunAction')
        ->and($source)->not->toContain('executeForArtifacts')
        ->and($source)->not->toContain('->apply(')
        ->and($source)->not->toContain('->remember(');
});
