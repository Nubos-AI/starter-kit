<?php

declare(strict_types=1);

use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ConfigBundle\ConfigBundleReader;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Promotion\PromotionSourceResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    $this->tenant = AccessContext::tenant();
    $this->sourceTenantId = ModelStub::ulid('source-tenant');

    $this->run = fn (array $overrides = []): PromotionRun => ModelStub::make(PromotionRun::class, [
        'id' => ModelStub::ulid('run'),
        'tenant_id' => $this->tenant->getKey(),
        'source_tenant_id' => $this->sourceTenantId,
        'triggered_by_id' => ModelStub::ulid('actor'),
        'counterpart_key' => 'sandbox:'.$this->sourceTenantId,
        'direction' => PromotionDirection::TenantToProduction->value,
        'status' => PromotionRunStatus::Approved->value,
        ...$overrides,
    ]);

    $this->importRun = fn (): PromotionRun => ($this->run)([
        'source_tenant_id' => null,
        'direction' => PromotionDirection::BundleImport->value,
        'counterpart_key' => 'bundle:'.ModelStub::ulid('bundle'),
    ]);

    $this->tenantSource = static function (?Throwable $refusal): TenantPromotionSourceInterface {
        return new class($refusal) implements TenantPromotionSourceInterface
        {
            /**
             * @var list<string>
             */
            public array $askedFor = [];

            public function __construct(private readonly ?Throwable $refusal) {}

            public function sourceFor(User $user): Tenant
            {
                throw new RuntimeException('not part of this test');
            }

            public function assertReady(PromotionRun $run): void
            {
                $this->askedFor[] = (string) $run->getKey();

                if ($this->refusal !== null) {
                    throw $this->refusal;
                }
            }

            public function assertContextAllowed(): void {}

            public function counterpartKey(Tenant $source): string
            {
                return 'sandbox:'.$source->getKey();
            }

            public function validationKey(): string
            {
                return 'sandbox';
            }
        };
    };

    $this->resolverWith = static fn (TenantPromotionSourceInterface $tenantSource): PromotionSourceResolver => new PromotionSourceResolver(
        app(ConfigBundleSerializer::class),
        app(ConfigBundleReader::class),
        $tenantSource,
    );

    $this->storedBundle = static function (string $directory, string $key): ConfigBundle {
        $bundle = new ConfigBundle(
            new BundleManifest(1, 'Aus dem Paket', [ArtifactKind::ObjectTypes->value => 1]),
            [new BundleArtifact(ArtifactKind::ObjectTypes, $key, ['key' => $key, 'name' => 'Aus dem Paket'])],
        );

        app(ConfigBundleSerializer::class)->writeTo($bundle, $directory);

        return $bundle;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('derives the storage directory of a run from the run alone without touching the database', function (): void {
    $run = ($this->importRun)();
    $resolver = ($this->resolverWith)(($this->tenantSource)(null));

    $directory = null;

    $shape = QueryShape::attemptedBy(static function () use ($resolver, $run, &$directory): void {
        $directory = $resolver->directoryFor($run);
    });

    expect($shape)->toBeNull()
        ->and($directory)->toBe("config-imports/{$this->tenant->getKey()}/{$run->getKey()}")
        ->and($directory)->not->toStartWith('/');
});

it('refuses a bundle import run without a stored directory and names no absolute path', function (): void {
    $run = ($this->importRun)();
    $resolver = ($this->resolverWith)(($this->tenantSource)(null));

    $expected = PromotionSourceUnavailableException::bundleDirectoryMissing();

    expect(fn (): mixed => $resolver->assertResolvable($run))
        ->toThrow(PromotionSourceUnavailableException::class, $expected->getMessage())
        ->and(fn (): ConfigBundle => $resolver->resolve($run))
        ->toThrow(PromotionSourceUnavailableException::class, $expected->getMessage())
        ->and($expected->getMessage())->not->toContain('/');
});

it('reads a bundle import run from the tree stored under its directory', function (): void {
    $run = ($this->importRun)();
    $resolver = ($this->resolverWith)(($this->tenantSource)(null));

    $stored = ($this->storedBundle)(
        Storage::disk('local')->path($resolver->directoryFor($run)),
        'imported',
    );

    $resolver->assertResolvable($run);
    $bundle = $resolver->resolve($run);

    expect($bundle->find(ArtifactKind::ObjectTypes, 'imported'))->toBeInstanceOf(BundleArtifact::class)
        ->and($bundle->find(ArtifactKind::ObjectTypes, 'imported')?->hash())
        ->toBe($stored->find(ArtifactKind::ObjectTypes, 'imported')?->hash())
        ->and($bundle->find(ArtifactKind::ObjectTypes, 'elsewhere'))->toBeNull();
});

it('passes a malformed bundle through unwrapped when the directory carries no manifest', function (): void {
    $run = ($this->importRun)();
    $resolver = ($this->resolverWith)(($this->tenantSource)(null));

    File::ensureDirectoryExists(Storage::disk('local')->path($resolver->directoryFor($run)));

    expect(fn (): mixed => $resolver->assertResolvable($run))->not->toThrow(PromotionSourceUnavailableException::class);

    expect(fn (): ConfigBundle => $resolver->resolve($run))->toThrow(MalformedBundleException::class);
});

it('hands a tenant run to the tenant source alone and passes its refusal through', function (?Throwable $refusal): void {
    $run = ($this->run)();
    $tenantSource = ($this->tenantSource)($refusal);
    $caught = null;

    try {
        ($this->resolverWith)($tenantSource)->assertResolvable($run);
    } catch (PromotionSourceUnavailableException $exception) {
        $caught = $exception;
    }

    expect($caught?->getMessage())->toBe($refusal?->getMessage())
        ->and($tenantSource->askedFor)->toBe([(string) $run->getKey()]);
})->with([
    'a ready tenant source' => [null],
    'a tenant source the tenant module declares unavailable' => [fn (): PromotionSourceUnavailableException => PromotionSourceUnavailableException::withoutSourceTenant()],
]);

it('states every source refusal in german', function (): void {
    expect(PromotionSourceUnavailableException::withoutSourceTenant()->getMessage())
        ->toBe('Für diese Promotion ist kein Quellmandant hinterlegt; ohne Quelle wird nichts übernommen.')
        ->and(PromotionSourceUnavailableException::sourceTenantMissing()->getMessage())
        ->toBe('Der Quellmandant dieser Promotion existiert nicht mehr; ohne Quelle wird nichts übernommen.')
        ->and(PromotionSourceUnavailableException::bundleDirectoryMissing()->getMessage())
        ->toBe('Für diesen Konfigurationsimport wurde kein Paket abgelegt; ohne Quelle wird nichts übernommen.')
        ->and(PromotionSourceUnavailableException::moduleMissing()->getMessage())
        ->toBe('Das erforderliche Modul für diese Übernahme ist nicht installiert.');
});
