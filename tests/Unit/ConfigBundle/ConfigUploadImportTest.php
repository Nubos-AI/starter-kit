<?php

declare(strict_types=1);

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Actions\ConfigBundle\PrepareConfigImportAction;
use App\Actions\ConfigBundle\ResolveImportPlaceholdersAction;
use App\DTOs\ConfigBundle\PlaceholderRequirement;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UnsafeArchiveException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Http\Controllers\ConfigBundle\ConfigBundleController;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\ConfigBundle\BundleArchiveExtractor;
use App\Support\ConfigBundle\BundleArchivePacker;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->root = sys_get_temp_dir().'/nubos-config-upload-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);

    URL::defaults([TeamSegment::key() => 'vertrieb']);

    $this->runId = ModelStub::ulid('promotion-run');
    $this->run = fn (): PromotionRun => ModelStub::make(PromotionRun::class, [
        'id' => $this->runId,
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->prepareState = new stdClass;
    $this->prepareState->calls = [];

    /** @var callable(PromotionRun|Throwable):PrepareConfigImportAction */
    $this->prepareAction = function (PromotionRun|Throwable $outcome, ?string $refusal = null): PrepareConfigImportAction {
        $state = $this->prepareState;

        $action = Mockery::mock(PrepareConfigImportAction::class);
        $action->shouldReceive('refusalFor')->andReturn($refusal);
        $action->shouldReceive('execute')->andReturnUsing(
            static function (User $actor, string $path) use ($state, $outcome): PromotionRun {
                $state->calls[] = $path;

                if ($outcome instanceof Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        return $action;
    };

    /** @var callable(list<PlaceholderRequirement>, ?string):ResolveImportPlaceholdersAction */
    $this->placeholderAction = static function (array $requirements, ?string $refusal = null): ResolveImportPlaceholdersAction {
        $action = Mockery::mock(ResolveImportPlaceholdersAction::class);
        $action->shouldReceive('requirementsFor')->andReturn($requirements);
        $action->shouldReceive('refusalFor')->andReturn($refusal);
        $action->shouldReceive('execute')->andReturnNull();

        return $action;
    };

    /** @var callable(?string):ExportConfigBundleAction */
    $this->exportAction = static function (?string $refusal = null): ExportConfigBundleAction {
        $action = Mockery::mock(ExportConfigBundleAction::class);
        $action->shouldReceive('refusalFor')->andReturn($refusal);

        return $action;
    };

    /** @var callable(PrepareConfigImportAction, ResolveImportPlaceholdersAction, ?ExportConfigBundleAction):ConfigBundleController */
    $this->controller = function (
        PrepareConfigImportAction $prepare,
        ?ResolveImportPlaceholdersAction $placeholders = null,
        ?ExportConfigBundleAction $export = null,
    ): ConfigBundleController {
        return new ConfigBundleController(
            $export ?? ($this->exportAction)(),
            $prepare,
            $placeholders ?? ($this->placeholderAction)([]),
            app(BundleArchivePacker::class),
        );
    };

    /** @var callable(?UploadedFile):Request */
    $this->uploadRequest = function (?UploadedFile $file): Request {
        $request = Request::create(
            '/engine/config/import',
            'POST',
            [],
            [],
            $file === null ? [] : ['file' => $file],
        );

        $request->setUserResolver(fn (): User => $this->actor);

        return $request;
    };

    /** @var callable(InertiaResponse):array<string, mixed> */
    $this->inertiaProps = static function (InertiaResponse $response): array {
        /** @var array<string, mixed> $props */
        $props = (new ReflectionProperty(InertiaResponse::class, 'props'))->getValue($response);

        return $props;
    };

    $this->inertiaComponent = static fn (InertiaResponse $response): string => (string) (new ReflectionProperty(
        InertiaResponse::class,
        'component',
    ))->getValue($response);

    /** @var callable(array<string, string>):string */
    $this->zipWith = function (array $entries): string {
        $path = $this->root.'/'.Str::ulid().'.zip';
        $zip = new ZipArchive;

        expect($zip->open($path, ZipArchive::CREATE))->toBeTrue();

        foreach ($entries as $name => $content) {
            expect($zip->addFromString($name, $content))->toBeTrue();
        }

        expect($zip->close())->toBeTrue();

        return $path;
    };

    $this->extractInto = function (string $zipPath): string {
        $target = $this->root.'/extracted-'.Str::ulid();
        File::ensureDirectoryExists($target);

        app(BundleArchiveExtractor::class)->extract($zipPath, $target);

        return $target;
    };
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
    File::deleteDirectory($this->root);
});

describe('routes and authorization', function (): void {
    it('registers every configuration route with its verb, path, controller method and permission middleware', function (string $name, string $verb, string $uri, string $method, ?string $permission, bool $bindsRun): void {
        $route = RouteShape::named($name);

        $permissionMiddleware = array_values(array_filter(
            $route->declaredMiddleware(),
            static fn (string $middleware): bool => str_starts_with($middleware, 'permission:'),
        ));

        expect($route->methods())->toContain($verb)
            ->and(str_ends_with($route->uri(), $uri))->toBeTrue()
            ->and($route->handledBy())->toBe(ConfigBundleController::class.'@'.$method)
            ->and($permissionMiddleware)->toBe($permission === null ? [] : ["permission:{$permission}"]);

        if ($bindsRun) {
            $pattern = $route->constraints()['run'] ?? null;

            expect($pattern)->toBeString()
                ->and(preg_match('/^'.$pattern.'$/', (string) Str::ulid()))->toBe(1)
                ->and(preg_match('/^'.$pattern.'$/', 'not-a-ulid'))->toBe(0);
        }
    })->with([
        'index' => ['engine.config.index', 'GET', 'engine/config', 'index', null, false],
        'export' => ['engine.config.export', 'GET', 'engine/config/export', 'export', 'config.export', false],
        'upload' => ['engine.config.import.upload', 'POST', 'engine/config/import', 'upload', 'config.import', false],
        'placeholders' => ['engine.config.import.placeholders', 'GET', 'engine/config/import/{run}/placeholders', 'placeholders', 'config.import', true],
        'store placeholders' => ['engine.config.import.placeholders.store', 'POST', 'engine/config/import/{run}/placeholders', 'storePlaceholders', 'config.import', true],
    ]);

    it('takes the run as a plain string and never binds a promotion run model implicitly', function (string $method): void {
        $parameters = (new ReflectionMethod(ConfigBundleController::class, $method))->getParameters();
        $runParameters = array_values(array_filter($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'run'));

        expect($runParameters)->toHaveCount(1)
            ->and((string) $runParameters[0]->getType())->toBe('string');

        foreach ($parameters as $parameter) {
            expect((string) $parameter->getType())->not->toContain(PromotionRun::class);
        }
    })->with([
        'placeholders' => ['placeholders'],
        'store placeholders' => ['storePlaceholders'],
    ]);

    it('refuses export and the placeholder step to a user without the matching right before any action or lookup runs', function (string $granted, Closure $call): void {
        AccessContext::grant($granted);

        $export = Mockery::mock(ExportConfigBundleAction::class);
        $export->shouldNotReceive('execute', 'refusalFor');

        $placeholders = Mockery::mock(ResolveImportPlaceholdersAction::class);
        $placeholders->shouldNotReceive('execute', 'requirementsFor', 'refusalFor');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()), $placeholders, $export);
        $request = ($this->uploadRequest)(null);

        $caught = null;

        $shape = QueryShape::attemptedBy(function () use ($call, $controller, $request, &$caught): void {
            try {
                $call($controller, $request, $this->runId);
            } catch (AuthorizationException $exception) {
                $caught = $exception;
            }
        });

        expect($caught)->toBeInstanceOf(AuthorizationException::class)
            ->and($shape)->toBeNull()
            ->and($this->prepareState->calls)->toBe([]);
    })->with([
        'export with config.import only' => ['config.import', static fn (ConfigBundleController $controller, Request $request, string $run): mixed => $controller->export($request)],
        'placeholders with config.export only' => ['config.export', static fn (ConfigBundleController $controller, Request $request, string $run): mixed => $controller->placeholders($request, $run)],
        'store placeholders with config.export only' => ['config.export', static fn (ConfigBundleController $controller, Request $request, string $run): mixed => $controller->storePlaceholders($request, $run)],
    ]);

    it('refuses the upload of a user without config.import and never touches the archive', function (array $permissions): void {
        AccessContext::grant(...$permissions);

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $request = ($this->uploadRequest)(UploadedFile::fake()->create('konfiguration.zip', 12));

        expect(fn () => $controller->upload($request))->toThrow(AuthorizationException::class)
            ->and($this->prepareState->calls)->toBe([]);
    })->with([
        'no configuration right' => [[]],
        'only config.export' => [['config.export']],
    ]);

    it('refuses the configuration page to a user holding neither configuration right', function (): void {
        AccessContext::grant('records.view');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $request = ($this->uploadRequest)(null);

        expect(fn () => $controller->index($request))->toThrow(AuthorizationException::class);
    });

    it('offers export and import on the configuration page exactly as the two actions decide', function (?string $exportRefusal, ?string $importRefusal): void {
        AccessContext::grant('config.export', 'config.import');

        $controller = ($this->controller)(
            ($this->prepareAction)(($this->run)(), $importRefusal),
            null,
            ($this->exportAction)($exportRefusal),
        );

        $response = $controller->index(($this->uploadRequest)(null));
        $props = ($this->inertiaProps)($response);

        expect(($this->inertiaComponent)($response))->toBe('config/Index')
            ->and($props['can_export'])->toBe($exportRefusal === null)
            ->and($props['export_reason'])->toBe($exportRefusal)
            ->and($props['can_import'])->toBe($importRefusal === null)
            ->and($props['import_reason'])->toBe($importRefusal);
    })->with([
        'both open' => [null, null],
        'export locked' => ['Ihnen fehlt die Berechtigung config.export.', null],
        'import locked' => [null, 'Der Import schreibt in die Konfiguration dieses Mandanten.'],
    ]);
});

describe('validating the upload', function (): void {
    it('refuses a request without a file on the file field and never asks the import action', function (): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));

        try {
            $controller->upload(($this->uploadRequest)(null));
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['file'])
                ->and($this->prepareState->calls)->toBe([]);

            return;
        }

        $this->fail('Expected a ValidationException for a request without a file.');
    });

    it('refuses a file without the zip extension on the file field and never asks the import action', function (string $fileName): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $request = ($this->uploadRequest)(UploadedFile::fake()->create($fileName, 12));

        try {
            $controller->upload($request);
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['file'])
                ->and($exception->errors()['file'][0])->toContain('ZIP')
                ->and($this->prepareState->calls)->toBe([]);

            return;
        }

        $this->fail("Expected a ValidationException for {$fileName}.");
    })->with([
        'a text file' => ['konfiguration.txt'],
        'a file without any extension' => ['konfiguration'],
        'a php file' => ['konfiguration.php'],
    ]);

    it('accepts the zip extension regardless of letter case', function (): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $request = ($this->uploadRequest)(UploadedFile::fake()->create('Konfiguration.ZIP', 12));

        $controller->upload($request);

        expect($this->prepareState->calls)->toHaveCount(1);
    });

    it('refuses an archive larger than the declared upload limit and never asks the import action', function (): void {
        AccessContext::grant('config.import');

        $limit = (new ReflectionProperty(ConfigBundleController::class, 'maxUploadKilobytes'))
            ->getValue(($this->controller)(($this->prepareAction)(($this->run)())));

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $request = ($this->uploadRequest)(UploadedFile::fake()->create('konfiguration.zip', (int) $limit + 1));

        try {
            $controller->upload($request);
        } catch (ValidationException $exception) {
            expect($limit)->toBe(20480)
                ->and(array_keys($exception->errors()))->toBe(['file'])
                ->and($this->prepareState->calls)->toBe([]);

            return;
        }

        $this->fail('Expected a ValidationException for an oversized archive.');
    });

    it('hands the real path of the uploaded archive to the import action', function (): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(($this->prepareAction)(($this->run)()));
        $file = UploadedFile::fake()->create('konfiguration.zip', 12);

        $controller->upload(($this->uploadRequest)($file));

        expect($this->prepareState->calls)->toBe([(string) $file->getRealPath()]);
    });

    it('leads on to the review when the bundle needs no placeholder and to the placeholder step otherwise', function (array $requirements, string $expected): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(
            ($this->prepareAction)(($this->run)()),
            ($this->placeholderAction)($requirements),
        );

        $response = $controller->upload(($this->uploadRequest)(UploadedFile::fake()->create('konfiguration.zip', 12)));

        expect($response->getStatusCode())->toBe(302)
            ->and($response->getTargetUrl())->toBe(route($expected, [
                $expected === 'engine.promotions.show' ? 'promotionRun' : 'run' => $this->runId,
            ]));
    })->with([
        'no requirement' => [[], 'engine.promotions.show'],
        'one requirement' => [[new PlaceholderRequirement('Deal Sync', 'Deal Sync', '__PLACEHOLDER__')], 'engine.config.import.placeholders'],
    ]);

    it('turns a refusal of the import action into an error on the file field', function (Throwable $failure): void {
        AccessContext::grant('config.import');

        $controller = ($this->controller)(($this->prepareAction)($failure));

        try {
            $controller->upload(($this->uploadRequest)(UploadedFile::fake()->create('konfiguration.zip', 12)));
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['file'])
                ->and($exception->errors()['file'][0])->toBe($failure->getMessage());

            return;
        }

        $this->fail('Expected the refusal to be reported on the file field.');
    })->with([
        'an unsafe archive' => [fn (): Throwable => UnsafeArchiveException::disallowedName('../evil.yaml')],
        'a malformed bundle' => [fn (): Throwable => MalformedBundleException::missingManifest('manifest.yaml')],
        'a newer schema version' => [fn (): Throwable => UnsupportedBundleSchemaVersionException::newerThanSupported(99, 4)],
    ]);
});

describe('the archive guard', function (): void {
    it('unpacks a clean archive into the target directory', function (): void {
        $zip = ($this->zipWith)([
            'manifest.yaml' => "schema_version: 4\n",
            'object-types/deal.yaml' => "key: deal\n",
        ]);

        $target = ($this->extractInto)($zip);

        $relatives = array_map(
            static fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()),
            File::allFiles($target),
        );
        sort($relatives);

        expect($relatives)->toBe(['manifest.yaml', 'object-types/deal.yaml'])
            ->and(File::get($target.'/object-types/deal.yaml'))->toBe("key: deal\n");
    });

    it('refuses an unsafe archive and writes nothing at all', function (string $variant): void {
        $clean = ['manifest.yaml' => "schema_version: 4\n"];

        $zip = match ($variant) {
            'zip slip' => ($this->zipWith)([...$clean, '../evil.yaml' => "key: evil\n"]),
            'absolute path' => ($this->zipWith)([...$clean, '/etc/evil.yaml' => "key: evil\n"]),
            'foreign file type' => ($this->zipWith)([...$clean, 'object-types/payload.php' => "<?php echo 'x';\n"]),
            'file and directory' => ($this->zipWith)([...$clean, 'roles.yaml' => "key: roles\n", 'roles.yaml/x.yaml' => "key: x\n"]),
            'file and directory in another letter case' => ($this->zipWith)([...$clean, 'Roles.yaml' => "key: roles\n", 'roles.yaml/x.yaml' => "key: x\n"]),
            'overlong file name' => ($this->zipWith)([...$clean, 'roles/'.str_repeat('d', 251).'.yaml' => "key: overlong\n"]),
            'overlong directory name' => ($this->zipWith)([...$clean, str_repeat('b', 256).'/x.yaml' => "key: overlong\n"]),
            'nested too deep' => ($this->zipWith)([...$clean, 'object-types/nested/deal.yaml' => "key: deal\n"]),
            'not a zip' => (function (): string {
                $path = $this->root.'/not-a-zip.zip';
                File::put($path, "Dies ist kein Archiv.\n");

                return $path;
            })(),
        };

        $target = $this->root.'/guarded-'.Str::ulid();
        File::ensureDirectoryExists($target);

        expect(fn () => app(BundleArchiveExtractor::class)->extract($zip, $target))
            ->toThrow(UnsafeArchiveException::class)
            ->and(File::allFiles($target))->toBe([])
            ->and(File::exists(dirname($target).'/evil.yaml'))->toBeFalse();
    })->with([
        'an entry breaking out of the target directory' => ['zip slip'],
        'an entry with an absolute path' => ['absolute path'],
        'an entry that is not a yaml file' => ['foreign file type'],
        'a yaml file that is also the parent directory of another entry' => ['file and directory'],
        'a yaml file whose name differs only in letter case from the parent directory of another entry' => ['file and directory in another letter case'],
        'a file name segment longer than the file system allows' => ['overlong file name'],
        'a directory name segment longer than the file system allows' => ['overlong directory name'],
        'an entry nested deeper than an artifact kind directory' => ['nested too deep'],
        'a container that is not a zip' => ['not a zip'],
    ]);

    it('refuses an archive that declares more entries than the guard allows', function (): void {
        $extractor = app(BundleArchiveExtractor::class);
        $previous = BundleArchiveExtractor::$maxEntries;

        BundleArchiveExtractor::$maxEntries = 1;

        $zip = ($this->zipWith)([
            'manifest.yaml' => "schema_version: 4\n",
            'object-types/deal.yaml' => "key: deal\n",
        ]);

        $target = $this->root.'/too-many-'.Str::ulid();
        File::ensureDirectoryExists($target);

        try {
            expect(fn () => $extractor->extract($zip, $target))->toThrow(UnsafeArchiveException::class)
                ->and(File::allFiles($target))->toBe([]);
        } finally {
            BundleArchiveExtractor::$maxEntries = $previous;
        }
    });

    it('refuses an archive whose uncompressed size exceeds the declared budget', function (): void {
        $extractor = app(BundleArchiveExtractor::class);
        $previous = BundleArchiveExtractor::$maxTotalUncompressedBytes;

        BundleArchiveExtractor::$maxTotalUncompressedBytes = 8;

        $zip = ($this->zipWith)(['manifest.yaml' => str_repeat('a', 4096)]);

        $target = $this->root.'/too-large-'.Str::ulid();
        File::ensureDirectoryExists($target);

        try {
            expect(fn () => $extractor->extract($zip, $target))->toThrow(UnsafeArchiveException::class)
                ->and(File::allFiles($target))->toBe([]);
        } finally {
            BundleArchiveExtractor::$maxTotalUncompressedBytes = $previous;
        }
    });
});
