<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\ConfigBundle\BusinessKeyResolver;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->root = sys_get_temp_dir().'/nubos-team-key-'.bin2hex(random_bytes(6));

    $this->salesId = ModelStub::ulid('team-sales');
    $this->serviceId = ModelStub::ulid('team-service');
    $this->dealTypeId = ModelStub::ulid('object-type-deal');
    $this->ruleId = ModelStub::ulid('team-rule');

    $this->filterTree = static fn (string $value = '76'): array => [
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => $value],
        ],
    ];

    /** @var callable(array<string, list<array<string, mixed>>>):BusinessKeyResolver */
    $this->resolverOf = static fn (array $rowsByTable): BusinessKeyResolver => new BusinessKeyResolver($rowsByTable);

    /** @var callable():array<string, list<array<string, mixed>>> */
    $this->rows = fn (): array => [
        'teams' => [
            ['id' => $this->salesId, 'slug' => 'sales', 'name' => 'Sales'],
            ['id' => $this->serviceId, 'slug' => 'service', 'name' => 'Service'],
        ],
        'object_types' => [
            ['id' => $this->dealTypeId, 'key' => 'deal', 'name' => 'Deal'],
        ],
        'team_record_access_rules' => [
            [
                'id' => $this->ruleId,
                'team_id' => $this->serviceId,
                'object_type_id' => $this->dealTypeId,
                'is_active' => true,
                'inheritance' => 'intersect',
                'filter_definition' => ($this->filterTree)(),
            ],
        ],
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('gives every team a key made of its bare slug', function (): void {
    $resolver = ($this->resolverOf)(($this->rows)());

    expect($resolver->keyFor('teams', $this->salesId))->toBe('sales')
        ->and($resolver->keyFor('teams', $this->serviceId))->toBe('service')
        ->and($resolver->keyFor('teams', ModelStub::ulid('unknown-team')))->toBeNull();
});

it('names a team access rule after the team and the object type it belongs to', function (): void {
    $resolver = ($this->resolverOf)(($this->rows)());

    expect($resolver->keyFor('team_record_access_rules', $this->ruleId))->toBe('service:deal');
});

it('leaves a team access rule keyless when the team behind it is missing from the rows', function (): void {
    $rows = ($this->rows)();
    $rows['teams'] = array_values(array_filter(
        $rows['teams'],
        fn (array $row): bool => $row['id'] !== $this->serviceId,
    ));

    $resolver = ($this->resolverOf)($rows);

    expect($resolver->keyFor('team_record_access_rules', $this->ruleId))->toBeNull();
});

it('knows only the teams it was handed so a same named team of another tenant is invisible', function (): void {
    $foreignTeamId = ModelStub::ulid('foreign-core');

    $own = ($this->resolverOf)([
        'teams' => [['id' => $this->salesId, 'slug' => 'core', 'name' => 'Core']],
    ]);

    $foreign = ($this->resolverOf)([
        'teams' => [['id' => $foreignTeamId, 'slug' => 'core', 'name' => 'Core']],
    ]);

    expect($own->keyFor('teams', $this->salesId))->toBe('core')
        ->and($own->keyFor('teams', $foreignTeamId))->toBeNull()
        ->and($foreign->keyFor('teams', $foreignTeamId))->toBe('core')
        ->and($foreign->keyFor('teams', $this->salesId))->toBeNull();
});

it('keeps a team identifier out of payload translation because teams are only a key source', function (): void {
    $resolver = ($this->resolverOf)(($this->rows)());

    expect(config('engine.config_bundle.key_source_tables'))->toContain('teams')
        ->and($resolver->keyFor('teams', $this->salesId))->toBe('sales')
        ->and($resolver->resolve($this->salesId))->toBeNull()
        ->and($resolver->resolve($this->dealTypeId))->toBe('deal');
});

it('collects the related team keys of a pivot sorted and without duplicates', function (): void {
    $pipelineId = ModelStub::ulid('pipeline-bestand');

    $rows = ($this->rows)();
    $rows['pipeline_team'] = [
        ['pipeline_id' => $pipelineId, 'team_id' => $this->serviceId],
        ['pipeline_id' => $pipelineId, 'team_id' => $this->salesId],
        ['pipeline_id' => $pipelineId, 'team_id' => $this->serviceId],
        ['pipeline_id' => ModelStub::ulid('other-pipeline'), 'team_id' => $this->salesId],
    ];

    $resolver = ($this->resolverOf)($rows);

    expect($resolver->relatedKeys('pipeline_team', 'pipeline_id', 'teams', 'team_id', $pipelineId))
        ->toBe(['sales', 'service'])
        ->and($resolver->relatedKeys('pipeline_team', 'pipeline_id', 'teams', 'team_id', null))
        ->toBe([]);
});

it('writes the file of a team access rule under the slugged team and object type', function (): void {
    $directory = $this->root.'/tree';

    app(ConfigBundleSerializer::class)->writeTo(
        new ConfigBundle(
            new BundleManifest(1, 'Quelle', [ArtifactKind::TeamRecordAccessRules->value => 1]),
            [new BundleArtifact(ArtifactKind::TeamRecordAccessRules, 'service:deal', [
                'key' => 'service:deal',
                'is_active' => true,
                'inheritance' => 'intersect',
                'filter_definition' => ($this->filterTree)(),
            ])],
        ),
        $directory,
    );

    $relatives = array_map(
        static fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()),
        File::allFiles($directory),
    );
    sort($relatives);

    expect($relatives)->toBe(['manifest.yaml', 'team-record-access-rules/service__deal.yaml']);
});
