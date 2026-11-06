<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Support\ConfigBundle\BusinessKeyResolver;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\YamlCodec;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AccessContext;
use Tests\Support\InstalledArtifactKinds;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->root = sys_get_temp_dir().'/nubos-bundle-'.bin2hex(random_bytes(6));
    $this->directory = $this->root.'/primary';

    $this->serializer = fn (): ConfigBundleSerializer => app(ConfigBundleSerializer::class);

    $this->bundledEntries = static function (): array {
        $entries = [];

        foreach ((array) config('engine.tenant_artifacts') as $entry) {
            if (is_array($entry) && ($entry['bundle'] ?? false) === true) {
                $entries[] = $entry;
            }
        }

        return $entries;
    };

    $this->entryOfTable = function (string $table): array {
        foreach (($this->bundledEntries)() as $entry) {
            if (($entry['table'] ?? null) === $table) {
                return $entry;
            }
        }

        throw new RuntimeException("No bundled artifact entry declares the table {$table}.");
    };

    $this->kindValues = static fn (): array => array_map(
        static fn (ArtifactKind $kind): string => $kind->value,
        ArtifactKind::cases(),
    );

    $this->personAndTeamColumns = [
        'owner_id',
        'created_by_id',
        'target_user_id',
        'target_team_id',
        'service_user_id',
    ];

    $this->runtimeStateColumns = [
        'webhook-subscriptions' => ['stage', 'rotated_at', 'activated_at', 'consecutive_failures', 'last_error'],
    ];

    /** @var callable(list<BundleArtifact>, list<string>):void */
    $this->write = function (array $artifacts, array $warnings = []): void {
        ($this->serializer)()->writeTo(
            new ConfigBundle(new BundleManifest(4, 'Acme Sales', ['skills' => count($artifacts)], $warnings), $artifacts),
            $this->directory,
        );
    };

    $this->tree = function (): array {
        $tree = [];

        foreach (File::allFiles($this->directory) as $file) {
            $tree[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }

        ksort($tree);

        return $tree;
    };

    $this->parse = fn (string $relative): array => app(YamlCodec::class)->parse(
        File::get($this->directory.'/'.$relative),
        $relative,
    );

    /** @var callable(array<string, mixed>, ArtifactKind, string, array<string, mixed>, BusinessKeyResolver):array<string, mixed> */
    $this->payloadOf = fn (array $entry, ArtifactKind $kind, string $key, array $row, BusinessKeyResolver $resolver): array => (new ReflectionMethod(
        ConfigBundleSerializer::class,
        'payloadOf',
    ))->invoke(($this->serializer)(), $entry, $kind, $key, $row, $resolver);
});

afterEach(function (): void {
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('writes one directory per artifact kind next to the manifest', function (): void {
    ($this->write)(array_map(
        static fn (ArtifactKind $kind): BundleArtifact => new BundleArtifact($kind, 'probe', ['key' => 'probe']),
        ArtifactKind::cases(),
    ));

    $tree = ($this->tree)();
    $directories = [];

    foreach (array_keys($tree) as $relative) {
        if (str_contains($relative, '/')) {
            $directories[] = explode('/', $relative)[0];
        }
    }

    expect($tree)->toHaveKey('manifest.yaml')
        ->and(array_values(array_unique($directories)))->toEqualCanonicalizing(($this->kindValues)());
});

it('maps every bundled table onto exactly one artifact kind and leaves none of the kinds unclaimed', function (): void {
    $claimed = [];
    $unmapped = [];
    $pivot = null;

    foreach (($this->bundledEntries)() as $entry) {
        $kind = $entry['kind'] ?? null;

        if (($entry['table'] ?? null) === 'role_permission') {
            $pivot = $kind;
        }

        if (!is_string($kind) || ArtifactKind::tryFrom($kind) === null) {
            $unmapped[] = (string) ($entry['table'] ?? '?');

            continue;
        }

        $claimed[] = $kind;
    }

    expect($unmapped)->toBe([])
        ->and($claimed)->toEqualCanonicalizing(InstalledArtifactKinds::values())
        ->and(array_values(array_unique($claimed)))->toHaveCount(count($claimed))
        ->and($pivot)->toBe(ArtifactKind::RolePermissions->value);
});

it('names each file after the slugged key components joined by two underscores while the file keeps the raw key', function (): void {
    ($this->write)([
        new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['key' => 'deal']),
        new BundleArtifact(ArtifactKind::Reports, 'deal:Revenue Q1', ['key' => 'deal:Revenue Q1']),
        new BundleArtifact(ArtifactKind::WebhookSubscriptions, 'Deal Sync', ['key' => 'Deal Sync']),
    ]);

    $tree = ($this->tree)();

    expect($tree)->toHaveKey('object-types/deal.yaml')
        ->and($tree)->toHaveKey('reports/deal__revenue-q1.yaml')
        ->and($tree)->toHaveKey('webhook-subscriptions/deal-sync.yaml')
        ->and(($this->parse)('object-types/deal.yaml')['key'])->toBe('deal')
        ->and(($this->parse)('reports/deal__revenue-q1.yaml')['key'])->toBe('deal:Revenue Q1')
        ->and(($this->parse)('webhook-subscriptions/deal-sync.yaml')['key'])->toBe('Deal Sync');
});

it('replaces an unslugabble or overlong key component with a deterministic hash instead of an empty or cut file name', function (): void {
    $unicodeName = '中文名';
    $overlongName = str_repeat('sales', 12);

    ($this->write)([
        new BundleArtifact(ArtifactKind::Skills, $unicodeName, ['key' => $unicodeName]),
        new BundleArtifact(ArtifactKind::Skills, $overlongName, ['key' => $overlongName]),
    ]);

    $tree = ($this->tree)();

    $expectedUnicodeFile = 'skills/'.substr(hash('sha256', $unicodeName), 0, 8).'.yaml';
    $expectedOverlongFile = 'skills/'
        .substr(Str::slug($overlongName), 0, 39)
        .'-'
        .substr(hash('sha256', $overlongName), 0, 8)
        .'.yaml';

    $offenders = [];

    foreach (array_keys($tree) as $relative) {
        foreach (explode('__', basename($relative, '.yaml')) as $component) {
            if ($component === '' || strlen($component) > 48) {
                $offenders[] = $relative;
            }
        }
    }

    expect($tree)->toHaveKey($expectedUnicodeFile)
        ->and($tree)->toHaveKey($expectedOverlongFile)
        ->and($offenders)->toBe([]);
});

it('refuses two artifacts whose file names collide and names both raw keys', function (): void {
    $claim = new ReflectionMethod(ConfigBundleSerializer::class, 'claimFileName');
    $serializer = ($this->serializer)();
    $fileNames = [];

    $claim->invokeArgs($serializer, [ArtifactKind::Skills, 'Revenue Q1', &$fileNames]);

    try {
        $claim->invokeArgs($serializer, [ArtifactKind::Skills, 'Revenue & Q1', &$fileNames]);
    } catch (AmbiguousArtifactKeyException $exception) {
        expect($exception->getMessage())->toContain(ArtifactKind::Skills->value)
            ->and($exception->getMessage())->toContain('Revenue Q1')
            ->and($exception->getMessage())->toContain('Revenue & Q1');

        return;
    }

    $this->fail('Expected an AmbiguousArtifactKeyException for two keys that slug to the same file name.');
});

it('builds every artifact kind from a configured field list that starts with the business key', function (): void {
    $configured = config('engine.config_bundle.fields');
    $configured = is_array($configured) ? $configured : [];

    $offenders = [];

    foreach (InstalledArtifactKinds::all() as $kind) {
        $fields = $configured[$kind->value] ?? null;

        if (!is_array($fields) || $fields === []) {
            $offenders[] = "{$kind->value}: no field list configured";

            continue;
        }

        if (($fields[0] ?? null) !== 'key') {
            $offenders[] = "{$kind->value}: the configured field list does not start with key";
        }

        if (array_values($fields) !== array_values(array_unique($fields))) {
            $offenders[] = "{$kind->value}: the configured field list repeats a field";
        }
    }

    expect($offenders)->toBe([]);
});

it('writes exactly the configured fields in the configured order and puts the business key first', function (): void {
    $entry = ($this->entryOfTable)('webhook_subscriptions');
    $kind = ArtifactKind::WebhookSubscriptions;
    $fields = (array) config("engine.config_bundle.fields.{$kind->value}");

    $payload = ($this->payloadOf)($entry, $kind, 'Deal Sync', [
        'id' => ModelStub::ulid('webhook'),
        'object_type_id' => 'deal',
        'role_id' => 'tenant:Integration',
        'target_url' => 'https://hooks.example.test/inbound',
        'event_types' => ['record.created'],
        'stage' => 'active',
        'consecutive_failures' => 3,
    ], new BusinessKeyResolver([]));

    expect(array_keys($payload))->toBe(array_values($fields))
        ->and(array_keys($payload)[0])->toBe('key')
        ->and($payload['key'])->toBe('Deal Sync')
        ->and($payload)->not->toHaveKey('stage')
        ->and($payload)->not->toHaveKey('consecutive_failures');
});

it('replaces every placeholder column with the configured placeholder value', function (): void {
    $entry = ($this->entryOfTable)('webhook_subscriptions');
    $placeholder = (string) config('engine.config_bundle.placeholder');

    $payload = ($this->payloadOf)($entry, ArtifactKind::WebhookSubscriptions, 'Deal Sync', [
        'id' => ModelStub::ulid('webhook'),
        'object_type_id' => 'deal',
        'role_id' => null,
        'target_url' => 'https://hooks.example.test/inbound',
        'event_types' => ['record.created'],
    ], new BusinessKeyResolver([]));

    expect($entry['placeholder_columns'])->toBe(['target_url'])
        ->and($placeholder)->not->toBe('')
        ->and($payload['target_url'])->toBe($placeholder)
        ->and($payload['target_url'])->not->toContain('hooks.example.test');
});

it('never lists a column the artifact whitelist marks as a secret in the exported field list', function (): void {
    $configured = config('engine.config_bundle.fields');
    $configured = is_array($configured) ? $configured : [];

    $offenders = [];
    $checked = 0;

    foreach (($this->bundledEntries)() as $entry) {
        $columns = $entry['secret_columns'] ?? [];

        if (!is_array($columns) || $columns === []) {
            continue;
        }

        $fields = (array) ($configured[(string) $entry['kind']] ?? []);

        foreach ($columns as $column) {
            $checked++;

            if (in_array((string) $column, $fields, true)) {
                $offenders[] = "{$entry['kind']}: {$column}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($offenders)->toBe([]);
});

it('refuses a configured field the row does not carry instead of writing it as a null value', function (): void {
    config(['engine.config_bundle.fields.skills' => ['key', 'missing_column']]);

    try {
        ($this->payloadOf)(($this->entryOfTable)('skills'), ArtifactKind::Skills, 'Negotiation', [
            'id' => ModelStub::ulid('skill'),
            'name' => 'Negotiation',
        ], new BusinessKeyResolver([]));
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('skills')
            ->and($exception->getMessage())->toContain('missing_column');

        return;
    }

    $this->fail('Expected an InvalidArgumentException for a configured field the row does not carry.');
});

it('refuses an artifact kind without a configured field list', function (): void {
    config(['engine.config_bundle.fields.skills' => []]);

    try {
        ($this->payloadOf)(($this->entryOfTable)('skills'), ArtifactKind::Skills, 'Negotiation', [
            'id' => ModelStub::ulid('skill'),
            'name' => 'Negotiation',
        ], new BusinessKeyResolver([]));
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('skills');

        return;
    }

    $this->fail('Expected an InvalidArgumentException for an artifact kind without a configured field list.');
});

it('drops person and team columns from every configured field list and covers each of them with a marker', function (): void {
    $configured = config('engine.config_bundle.fields');
    $configured = is_array($configured) ? $configured : [];

    $markers = array_map(
        static fn (mixed $marker): string => (string) $marker,
        (array) config('engine.config_bundle.non_portable_markers'),
    );

    $offenders = [];

    foreach ($configured as $kindValue => $fields) {
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (in_array($field, $this->personAndTeamColumns, true)) {
                $offenders[] = "{$kindValue}: {$field}";
            }
        }
    }

    $uncoveredColumns = [];

    foreach ($this->personAndTeamColumns as $column) {
        $covered = false;

        foreach ($markers as $marker) {
            $covered = $covered || str_contains($column, $marker);
        }

        if (!$covered) {
            $uncoveredColumns[] = $column;
        }
    }

    expect($offenders)->toBe([])
        ->and($markers)->not->toBeEmpty()
        ->and($uncoveredColumns)->toBe([]);
});

it('leaves runtime state out of the configured field lists so an export does not drift with operations', function (): void {
    $configured = config('engine.config_bundle.fields');
    $configured = is_array($configured) ? $configured : [];

    $offenders = [];
    $checked = 0;

    foreach ($this->runtimeStateColumns as $kindValue => $columns) {
        $fields = (array) ($configured[$kindValue] ?? []);

        expect($fields)->not->toBe([]);

        foreach ($columns as $column) {
            $checked++;

            if (in_array($column, $fields, true)) {
                $offenders[] = "{$kindValue}: {$column}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($offenders)->toBe([]);
});

it('writes a manifest whose keys, source label and counts carry over into the file', function (): void {
    ($this->write)([new BundleArtifact(ArtifactKind::Skills, 'Negotiation', ['key' => 'Negotiation'])], ['skills:Negotiation → owner_id']);

    $manifest = ($this->parse)('manifest.yaml');

    expect(array_keys($manifest))->toBe([
        'schema_version',
        'source_label',
        'artifact_counts',
        'warnings',
    ])
        ->and($manifest['source_label'])->toBe('Acme Sales')
        ->and($manifest['schema_version'])->toBe(4)
        ->and($manifest['artifact_counts'])->toBe(['skills' => 1])
        ->and($manifest['warnings'])->toBe(['skills:Negotiation → owner_id']);
});

it('keeps the session and the yaml library out of the bundle serialisation', function (): void {
    $offenders = [];
    $supportPath = app_path('Support/ConfigBundle');

    foreach (File::allFiles($supportPath) as $file) {
        if (str_contains($file->getContents(), 'Auth::')) {
            $offenders[] = 'Auth:: in '.$file->getRelativePathname();
        }
    }

    foreach (File::allFiles(app_path()) as $file) {
        if (!str_contains($file->getContents(), 'Symfony\Component\Yaml')) {
            continue;
        }

        if ($file->getFilename() !== 'YamlCodec.php') {
            $offenders[] = 'Yaml in '.$file->getRelativePathname();
        }
    }

    expect(File::isDirectory($supportPath))->toBeTrue()
        ->and($offenders)->toBe([]);
});

it('exports two widgets of the same dashboard under stable ordinals', function (): void {
    $dashboardId = ModelStub::ulid('dashboard-sales');
    $pipelineTileId = ModelStub::ulid('widget-pipeline');
    $revenueTileId = ModelStub::ulid('widget-revenue');

    $rows = [
        'dashboards' => [['id' => $dashboardId, 'name' => 'Sales Overview', 'is_tenant_wide' => true]],
        'dashboard_widgets' => [
            ['id' => $revenueTileId, 'dashboard_id' => $dashboardId, 'title' => 'Revenue Tile', 'position' => 2],
            ['id' => $pipelineTileId, 'dashboard_id' => $dashboardId, 'title' => 'Pipeline Tile', 'position' => 1],
        ],
    ];

    $first = new BusinessKeyResolver($rows);
    $second = new BusinessKeyResolver([
        'dashboards' => $rows['dashboards'],
        'dashboard_widgets' => array_reverse($rows['dashboard_widgets']),
    ]);

    expect($first->keyFor('dashboard_widgets', $pipelineTileId))->toBe('Sales Overview:1')
        ->and($first->keyFor('dashboard_widgets', $revenueTileId))->toBe('Sales Overview:2')
        ->and($second->keyFor('dashboard_widgets', $pipelineTileId))->toBe('Sales Overview:1')
        ->and($second->keyFor('dashboard_widgets', $revenueTileId))->toBe('Sales Overview:2');
});

it('leaves a personal dashboard and its widgets without a business key', function (): void {
    $personalId = ModelStub::ulid('dashboard-my-desk');
    $widgetId = ModelStub::ulid('widget-my-desk');

    $resolver = new BusinessKeyResolver([
        'dashboards' => [['id' => $personalId, 'name' => 'My Desk', 'is_tenant_wide' => false]],
        'dashboard_widgets' => [['id' => $widgetId, 'dashboard_id' => $personalId, 'title' => 'Tile', 'position' => 1]],
    ]);

    expect($resolver->keyFor('dashboards', $personalId))->toBeNull()
        ->and($resolver->keyFor('dashboard_widgets', $widgetId))->toBeNull();
});

it('keeps the yaml settings that force block style and refuse an unrepresentable value', function (): void {
    $codec = app(YamlCodec::class);

    $settingOf = static fn (string $name): mixed => (new ReflectionProperty(YamlCodec::class, $name))->getValue($codec);

    expect($settingOf('inlineDepth'))->toBe(32)
        ->and($settingOf('indentation'))->toBe(2)
        ->and($settingOf('dumpFlags'))->toBe(
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE,
        )
        ->and($settingOf('dumpFlags') & Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE)->toBe(Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
});

it('writes a payload nested nine levels deep in block style instead of folding it into flow style', function (): void {
    $leaf = 'tief-verschachtelt';

    $payload = ['key' => 'deal:Lead Routing', 'deep' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => ['l8' => ['l9' => ['leaf' => $leaf]]]]]]]]];

    ($this->write)([new BundleArtifact(ArtifactKind::Automations, 'deal:Lead Routing', $payload)]);

    $content = File::get($this->directory.'/automations/deal__lead-routing.yaml');

    $missingLevels = [];

    foreach (['deep', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8', 'l9'] as $depth => $level) {
        $line = "\n".str_repeat(' ', 2 * $depth).$level.":\n";

        if (!str_contains($content, $line)) {
            $missingLevels[] = $level;
        }
    }

    expect($content)->toContain("\n".str_repeat(' ', 16).'leaf: '.$leaf."\n")
        ->and($missingLevels)->toBe([]);
});
