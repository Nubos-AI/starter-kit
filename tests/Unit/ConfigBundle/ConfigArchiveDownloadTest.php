<?php

declare(strict_types=1);

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Actions\ConfigBundle\PrepareConfigImportAction;
use App\Actions\ConfigBundle\ResolveImportPlaceholdersAction;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Http\Controllers\ConfigBundle\ConfigBundleController;
use App\Models\User;
use App\Support\ConfigBundle\BundleArchivePacker;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use ZipStream\ZipStream;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->travelTo(CarbonImmutable::parse('2030-05-17 09:30:00', 'UTC'));

    $this->root = sys_get_temp_dir().'/nubos-config-download-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);

    $this->placeholder = (string) config('engine.config_bundle.placeholder');

    $this->bundle = fn (string $sourceLabel = 'Acme Sales'): ConfigBundle => new ConfigBundle(
        new BundleManifest(4, $sourceLabel, [
            ArtifactKind::ObjectTypes->value => 1,
            ArtifactKind::Skills->value => 1,
            ArtifactKind::WebhookSubscriptions->value => 1,
        ]),
        [
            new BundleArtifact(ArtifactKind::Skills, 'Negotiation', ['key' => 'Negotiation']),
            new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['key' => 'deal', 'name' => 'Deal']),
            new BundleArtifact(ArtifactKind::WebhookSubscriptions, 'Deal Sync', [
                'key' => 'Deal Sync',
                'target_url' => $this->placeholder,
                'event_types' => ['record.created'],
            ]),
        ],
    );

    $this->exportState = new stdClass;
    $this->exportState->entryPoints = [];

    /** @var callable(?string, ConfigBundle|Throwable|null):ExportConfigBundleAction */
    $this->exportAction = function (?string $refusal, ConfigBundle|Throwable|null $outcome = null): ExportConfigBundleAction {
        $state = $this->exportState;
        $result = $outcome ?? ($this->bundle)();

        $action = Mockery::mock(ExportConfigBundleAction::class);
        $action->shouldReceive('refusalFor')->andReturn($refusal);
        $action->shouldReceive('execute')->andReturnUsing(
            static function (User $actor, ConfigExportEntryPoint $entryPoint) use ($state, $result): ConfigBundle {
                $state->entryPoints[] = $entryPoint->value;

                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            },
        );

        return $action;
    };

    /** @var callable(ExportConfigBundleAction):ConfigBundleController */
    $this->controller = static function (ExportConfigBundleAction $export): ConfigBundleController {
        $prepare = Mockery::mock(PrepareConfigImportAction::class);
        $prepare->shouldReceive('refusalFor')->andReturnNull();

        $placeholders = Mockery::mock(ResolveImportPlaceholdersAction::class);
        $placeholders->shouldReceive('requirementsFor')->andReturn([]);

        return new ConfigBundleController($export, $prepare, $placeholders, app(BundleArchivePacker::class));
    };

    $this->exportRequest = function (): Request {
        $request = Request::create('/engine/config/export', 'GET');
        $request->setUserResolver(fn (): User => $this->actor);

        return $request;
    };

    /** @var callable(StreamedResponse):string */
    $this->archiveBytes = static function (StreamedResponse $response): string {
        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        expect(str_starts_with($bytes, "PK\x03\x04"))->toBeTrue();

        return $bytes;
    };

    /** @var callable(string):list<array{name: string, content: string}> */
    $this->entriesOf = function (string $bytes): array {
        $path = $this->root.'/'.Str::ulid().'.zip';
        File::put($path, $bytes);

        $zip = new ZipArchive;

        expect($zip->open($path, ZipArchive::CHECKCONS))->toBeTrue();

        $entries = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $content = $zip->getFromIndex($index);

                expect($content)->toBeString();

                $entries[] = ['name' => (string) $zip->getNameIndex($index), 'content' => (string) $content];
            }
        } finally {
            $zip->close();
        }

        return $entries;
    };

    /** @var callable(string):array<string, array{central: int, local: int}> */
    $this->dosTimestampsOf = static function (string $bytes): array {
        $endOfDirectory = strrpos($bytes, "PK\x05\x06");

        expect($endOfDirectory)->not->toBeFalse();

        $entryCount = unpack('v', substr($bytes, (int) $endOfDirectory + 10, 2))[1];
        $offset = unpack('V', substr($bytes, (int) $endOfDirectory + 16, 4))[1];
        $stamps = [];

        for ($index = 0; $index < $entryCount; $index++) {
            expect(substr($bytes, $offset, 4))->toBe("PK\x01\x02");

            $nameLength = unpack('v', substr($bytes, $offset + 28, 2))[1];
            $extraLength = unpack('v', substr($bytes, $offset + 30, 2))[1];
            $commentLength = unpack('v', substr($bytes, $offset + 32, 2))[1];
            $localOffset = unpack('V', substr($bytes, $offset + 42, 4))[1];

            expect(substr($bytes, $localOffset, 4))->toBe("PK\x03\x04");

            $stamps[substr($bytes, $offset + 46, $nameLength)] = [
                'central' => unpack('V', substr($bytes, $offset + 12, 4))[1],
                'local' => unpack('V', substr($bytes, $localOffset + 10, 4))[1],
            ];

            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $stamps;
    };
});

afterEach(function (): void {
    $this->travelBack();
    AccessContext::forgetTenant();
    File::deleteDirectory($this->root);
});

describe('the archive download', function (): void {
    it('streams the archive as a zip attachment named after the tenant and the current UTC date', function (): void {
        AccessContext::grant('config.export');

        $response = ($this->controller)(($this->exportAction)(null))->export(($this->exportRequest)());
        $bytes = ($this->archiveBytes)($response);
        $disposition = (string) $response->headers->get('Content-Disposition');

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and(str_starts_with($disposition, 'attachment'))->toBeTrue()
            ->and($disposition)->toContain('konfiguration-acme-sales-2030-05-17.zip')
            ->and((string) $response->headers->get('Content-Type'))->toContain('application/zip')
            ->and(strlen($bytes))->toBeGreaterThan(22)
            ->and($this->exportState->entryPoints)->toBe([ConfigExportEntryPoint::Download->value]);
    });

    it('falls back on a neutral file name when the tenant name slugs to nothing', function (): void {
        AccessContext::grant('config.export');

        $response = ($this->controller)(($this->exportAction)(null, ($this->bundle)('中文名')))->export(($this->exportRequest)());

        ($this->archiveBytes)($response);

        expect((string) $response->headers->get('Content-Disposition'))->toContain('konfiguration-mandant-2030-05-17.zip');
    });

    it('packs manifest.yaml first and then every artifact file in sorted order', function (): void {
        AccessContext::grant('config.export');

        $bundle = ($this->bundle)();
        $entries = ($this->entriesOf)(($this->archiveBytes)(
            ($this->controller)(($this->exportAction)(null, $bundle))->export(($this->exportRequest)()),
        ));

        $names = array_column($entries, 'name');
        $artifactNames = array_slice($names, 1);
        $sortedNames = $artifactNames;
        sort($sortedNames);

        foreach ($names as $name) {
            expect(str_ends_with($name, '.yaml'))->toBeTrue();
        }

        $manifest = Yaml::parse($entries[0]['content']);

        expect($names[0] ?? null)->toBe('manifest.yaml')
            ->and($names)->toHaveCount(1 + count($bundle->artifacts))
            ->and(array_unique($names))->toHaveCount(count($names))
            ->and($artifactNames)->toBe($sortedNames)
            ->and($artifactNames)->toBe([
                'object-types/deal.yaml',
                'skills/negotiation.yaml',
                'webhook-subscriptions/deal-sync.yaml',
            ])
            ->and($manifest['source_label'] ?? null)->toBe('Acme Sales')
            ->and($manifest['artifact_counts'] ?? null)->toBe($bundle->manifest->artifactCounts);
    });

    it('produces two archives of the same bundle that are equal entry by entry', function (): void {
        AccessContext::grant('config.export');

        $controller = ($this->controller)(($this->exportAction)(null));

        $first = ($this->entriesOf)(($this->archiveBytes)($controller->export(($this->exportRequest)())));

        $this->travel(7)->minutes();

        $second = ($this->entriesOf)(($this->archiveBytes)($controller->export(($this->exportRequest)())));

        expect(count($first))->toBeGreaterThan(1)
            ->and($second)->toBe($first);
    });

    it('stamps every entry of the archive with the fixed modification time instead of now', function (): void {
        AccessContext::grant('config.export');

        $bytes = ($this->archiveBytes)(
            ($this->controller)(($this->exportAction)(null))->export(($this->exportRequest)()),
        );

        $stamps = ($this->dosTimestampsOf)($bytes);

        expect(array_keys($stamps))->toBe(array_column(($this->entriesOf)($bytes), 'name'));

        foreach ($stamps as $stamp) {
            expect($stamp['central'])->toBe(0x28210000)
                ->and($stamp['local'])->toBe(0x28210000);
        }

        $zip64 = array_values(array_filter(
            (new ReflectionMethod(ZipStream::class, '__construct'))->getParameters(),
            static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'enableZip64',
        ));

        expect($zip64)->toHaveCount(1)
            ->and($zip64[0]->getDefaultValue())->toBeTrue();
    });

    it('leaves no scratch directory behind once the archive has been streamed', function (): void {
        AccessContext::grant('config.export');

        $scratchOf = static function (): array {
            $tmp = storage_path('app/private/tmp');

            if (!File::isDirectory($tmp)) {
                return [];
            }

            $found = array_values(array_filter(
                File::directories($tmp),
                static fn (string $directory): bool => str_starts_with(basename($directory), 'config-export-'),
            ));
            sort($found);

            return $found;
        };

        $before = $scratchOf();

        ($this->archiveBytes)(($this->controller)(($this->exportAction)(null))->export(($this->exportRequest)()));

        expect($scratchOf())->toBe($before);
    });
});

describe('refusing the download', function (): void {
    it('refuses the download to a user without config.export and never asks the export action', function (array $permissions): void {
        AccessContext::grant(...$permissions);

        $controller = ($this->controller)(($this->exportAction)(null));

        expect(fn () => $controller->export(($this->exportRequest)()))->toThrow(AuthorizationException::class)
            ->and($this->exportState->entryPoints)->toBe([]);
    })->with([
        'no configuration right' => [[]],
        'only config.import' => [['config.import']],
    ]);

    it('refuses the download of a tenant whose artifact keys collide on the export field instead of a server error', function (): void {
        AccessContext::grant('config.export');

        $collision = AmbiguousArtifactKeyException::fileNameCollision(
            ArtifactKind::Skills,
            'Revenue Q1',
            'Revenue & Q1',
            'skills/revenue-q1.yaml',
        );

        $controller = ($this->controller)(($this->exportAction)(null, $collision));

        try {
            $controller->export(($this->exportRequest)());
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['export'])
                ->and($exception->errors()['export'][0])->toContain(ArtifactKind::Skills->value)
                ->and($exception->errors()['export'][0])->toContain('Revenue Q1')
                ->and($exception->errors()['export'][0])->toContain('Revenue & Q1');

            return;
        }

        $this->fail('Expected a ValidationException on the export field for colliding artifact keys.');
    });
});
