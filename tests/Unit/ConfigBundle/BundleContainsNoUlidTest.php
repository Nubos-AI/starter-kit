<?php

declare(strict_types=1);

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\ConfigBundle\BusinessKeyResolver;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\PayloadRewriter;
use App\Support\ConfigBundle\YamlCodec;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->root = sys_get_temp_dir().'/nubos-no-ulid-'.bin2hex(random_bytes(6));
    $this->directory = $this->root.'/tree';

    $this->placeholder = (string) config('engine.config_bundle.placeholder');

    $this->objectTypeId = ModelStub::ulid('object-type-deal');
    $this->stageOpenId = ModelStub::ulid('stage-open');
    $this->stageWonId = ModelStub::ulid('stage-won');
    $this->pipelineId = ModelStub::ulid('pipeline-default');
    $this->personId = ModelStub::ulid('person');
    $this->vanishedId = ModelStub::ulid('vanished-artifact');

    $this->rowsByTable = [
        'object_types' => [
            ['id' => $this->objectTypeId, 'key' => 'deal', 'name' => 'Deal'],
        ],
        'pipelines' => [
            ['id' => $this->pipelineId, 'object_type_id' => $this->objectTypeId, 'key' => 'default'],
        ],
        'pipeline_stages' => [
            ['id' => $this->stageOpenId, 'pipeline_id' => $this->pipelineId, 'key' => 'open'],
            ['id' => $this->stageWonId, 'pipeline_id' => $this->pipelineId, 'key' => 'won'],
        ],
    ];

    $this->resolver = function (): BusinessKeyResolver {
        /** @var class-string<BusinessKeyResolver> $resolverClass */
        $resolverClass = config('engine.config_bundle.resolver', BusinessKeyResolver::class);

        return new $resolverClass($this->rowsByTable);
    };

    /** @var callable(BusinessKeyResolver):PayloadRewriter */
    $this->rewriterOf = static fn (BusinessKeyResolver $resolver): PayloadRewriter => new PayloadRewriter($resolver);

    /** @var callable(PayloadRewriter, ArtifactKind, string, array<string, mixed>):BundleArtifact */
    $this->artifactOf = static fn (PayloadRewriter $rewriter, ArtifactKind $kind, string $key, array $payload): BundleArtifact => new BundleArtifact(
        $kind,
        $key,
        app(YamlCodec::class)->normalize($payload, $rewriter->translatorFor($kind->identifierFor($key))),
    );

    $this->automationPayload = fn (): array => [
        'key' => 'deal:Lead Routing',
        'trigger' => ['object_type_id' => $this->objectTypeId],
        'nodes' => [
            ['type' => 'move', 'config' => ['stage_id' => $this->stageWonId]],
            ['type' => 'assign', 'config' => ['fallback_user_id' => $this->personId]],
            ['type' => 'notify', 'config' => ['linked_segment' => $this->vanishedId]],
        ],
    ];

    $this->write = function (array $artifacts, array $warnings): void {
        app(ConfigBundleSerializer::class)->writeTo(
            new ConfigBundle(
                new BundleManifest(1, 'Acme Sales', [], $warnings),
                $artifacts,
            ),
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

});

afterEach(function (): void {
    AccessContext::forgetTenant();

    if (is_string($this->root ?? null)) {
        File::deleteDirectory($this->root);
    }
});

it('turns an unresolvable identifier into the configured placeholder and reports it as a warning', function (): void {
    $rewriter = ($this->rewriterOf)(($this->resolver)());

    $automation = ($this->artifactOf)($rewriter, ArtifactKind::Automations, 'deal:Lead Routing', ($this->automationPayload)());

    ($this->write)([$automation], $rewriter->warnings());

    $joinedWarnings = implode("\n", $rewriter->warnings());
    $joinedTree = implode("\n", ($this->tree)());

    expect($this->placeholder)->not->toBe('')
        ->and($automation->payload['nodes'][1]['config']['fallback_user_id'])->toBe($this->placeholder)
        ->and($joinedWarnings)->toContain(ArtifactKind::Automations->value)
        ->and($joinedWarnings)->toContain('deal:Lead Routing')
        ->and($joinedWarnings)->toContain('fallback_user_id')
        ->and($joinedWarnings)->not->toContain($this->personId)
        ->and($joinedTree)->not->toContain($this->personId);
});

it('tells a reference to a person or a team apart from one whose target was not found', function (): void {
    $rewriter = ($this->rewriterOf)(($this->resolver)());

    ($this->artifactOf)($rewriter, ArtifactKind::Automations, 'deal:Lead Routing', ($this->automationPayload)());

    $reasonOf = static function (string $needle) use ($rewriter): ?string {
        foreach ($rewriter->warnings() as $warning) {
            if (str_contains($warning, $needle)) {
                return (string) Str::after($warning, ': ');
            }
        }

        return null;
    };

    $markers = config('engine.config_bundle.non_portable_markers');

    expect($markers)->toBeArray()
        ->and($markers)->toContain('owner_id')
        ->and($markers)->toContain('created_by_id')
        ->and($reasonOf('fallback_user_id'))->not->toBeNull()
        ->and($reasonOf('linked_segment'))->not->toBeNull()
        ->and($reasonOf('linked_segment'))->not->toBe($reasonOf('fallback_user_id'));
});

it('leaves a string that is not an identifier untouched instead of hiding it behind the placeholder', function (): void {
    $rewriter = ($this->rewriterOf)(($this->resolver)());

    $artifact = ($this->artifactOf)($rewriter, ArtifactKind::Reports, 'deal:Revenue Q1', [
        'key' => 'deal:Revenue Q1',
        'title' => 'Umsatz Q1',
        'threshold' => '1000',
        'object_type_id' => $this->objectTypeId,
    ]);

    expect($artifact->payload['title'])->toBe('Umsatz Q1')
        ->and($artifact->payload['threshold'])->toBe('1000')
        ->and($artifact->payload['object_type_id'])->toBe('deal')
        ->and($rewriter->warnings())->toBe([]);
});
