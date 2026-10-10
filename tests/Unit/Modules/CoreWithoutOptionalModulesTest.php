<?php

declare(strict_types=1);

use App\Activities\Notifications\EvaluateNotificationRulesActivity;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Handlers\ConfigBundle\CommunicationArtifactWriter;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\Modules\AllowOutboundGuard;
use App\Support\Modules\ModuleCatalog;
use App\Workflows\Engine\RecordChangeRelayWorkflow;
use Illuminate\Support\Facades\Artisan;
use Keepsuit\LaravelTemporal\TemporalRegistry;
use Symfony\Component\Process\Process;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):SchemaShape */
    $this->coreMigration = static fn (string $file): SchemaShape => SchemaShape::ofMigration('database/migrations/'.$file);

    /** @var callable():string */
    $this->allCoreMigrationSource = static function (): string {
        $source = '';

        foreach ((array) glob(base_path('database/migrations/*.php')) as $path) {
            $source .= (string) file_get_contents((string) $path);
        }

        return $source;
    };
});

it('carries no pipeline column in the core record table', function (): void {
    $columns = ($this->coreMigration)('0001_01_01_000014_create_custom_records_table.php')->columnsOf('custom_records');

    expect($columns)->not->toContain('pipeline_id')
        ->and($columns)->not->toContain('stage_id')
        ->and($columns)->not->toContain('stage_entered_at');
});

it('carries no pipeline card flag in the core field definition table', function (): void {
    expect(($this->coreMigration)('0001_01_01_000012_create_field_definitions_table.php')->columnsOf('field_definitions'))
        ->not->toContain('is_card_field');
});

it('creates no pipeline table anywhere in the core migrations', function (): void {
    $source = ($this->allCoreMigrationSource)();

    expect($source)->not->toContain("create('pipelines'")
        ->and($source)->not->toContain("create('pipeline_stages'")
        ->and($source)->not->toContain("create('stage_transitions'");
});

it('keeps record processing scheduled and relayed without any optional module', function (): void {
    $registry = app(TemporalRegistry::class);

    expect(Artisan::all())->toHaveKey('records:register-schedules')
        ->and($registry->workflows())->toContain(RecordChangeRelayWorkflow::class)
        ->and($registry->activities())->toContain(EvaluateNotificationRulesActivity::class);
});

it('keeps the notification and webhook bundle writers in the core', function (): void {
    $registry = app(ArtifactWriterRegistry::class);

    foreach ([ArtifactKind::NotificationRules, ArtifactKind::NotificationTypeDefaults, ArtifactKind::WebhookSubscriptions] as $kind) {
        expect($registry->for($kind))->toBeInstanceOf(CommunicationArtifactWriter::class);
    }
});

it('boots, routes and answers with the plain core when no module provider is discovered', function (): void {
    $directory = sys_get_temp_dir().'/nubos-no-modules-'.bin2hex(random_bytes(8));
    mkdir($directory);

    $manifest = require base_path('bootstrap/cache/packages.php');
    foreach (app(ModuleCatalog::class)->all() as $module) {
        unset($manifest[$module]);
    }

    file_put_contents($directory.'/packages.php', '<?php return '.var_export($manifest, true).';');

    $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'outboundGuard' => $app->make(App\Contracts\Modules\OutboundGuardInterface::class)::class,
            'loginRoute' => Illuminate\Support\Facades\Route::has('login'),
            'automationsRoute' => Illuminate\Support\Facades\Route::has('engine.automations.index'),
            'automationPermissions' => config('permissions.groups.automations'),
            'automationTimelineSource' => array_key_exists('automation_run', config('timeline.sources')),
            'stageWebhook' => in_array('record.stage_changed', config('webhooks.events'), true),
            'mergeOptions' => config('modules.merge.option_keys', []),
            'recordSchedules' => array_key_exists('records:register-schedules', Illuminate\Support\Facades\Artisan::all()),
            'tenantFillable' => (new App\Models\Tenant)->getFillable(),
        ], JSON_THROW_ON_ERROR);
        PHP;

    try {
        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
            'APP_PACKAGES_CACHE' => $directory.'/packages.php',
            'APP_SERVICES_CACHE' => $directory.'/services.php',
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'APP_ROUTES_CACHE' => $directory.'/routes.php',
        ]);

        $process->setTimeout(120)->mustRun();

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['outboundGuard'])->toBe(AllowOutboundGuard::class)
            ->and($result['loginRoute'])->toBeTrue()
            ->and($result['automationsRoute'])->toBeFalse()
            ->and($result['automationPermissions'])->toBeNull()
            ->and($result['automationTimelineSource'])->toBeFalse()
            ->and($result['stageWebhook'])->toBeFalse()
            ->and($result['mergeOptions'])->toBe([])
            ->and($result['recordSchedules'])->toBeTrue()
            ->and($result['tenantFillable'])->toBe(['name', 'slug']);
    } finally {
        foreach ((array) glob($directory.'/*') as $file) {
            unlink((string) $file);
        }

        rmdir($directory);
    }
});
