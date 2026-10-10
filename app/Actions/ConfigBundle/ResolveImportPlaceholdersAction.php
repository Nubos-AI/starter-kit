<?php

declare(strict_types=1);

namespace App\Actions\ConfigBundle;

use App\DTOs\ConfigBundle\PlaceholderRequirement;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\ConfigBundle\BundlePlaceholderResolver;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Promotion\PromotionSourceResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResolveImportPlaceholdersAction
{
    private string $notDraftReason = 'i18n.backend.actions.config_bundle.resolve_import_placeholders_action.this_transfer_is_no_longer_a_draft_webhook_destinations';

    private int $maxTargetLength = 2048;

    public function __construct(
        private readonly PrepareConfigImportAction $prepareConfigImport,
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly BundlePlaceholderResolver $placeholders,
        private readonly ConfigBundleSerializer $serializer,
    ) {}

    /**
     * @return list<PlaceholderRequirement>
     *
     * @throws PromotionSourceUnavailableException
     * @throws MalformedBundleException
     * @throws UnsupportedBundleSchemaVersionException
     */
    public function requirementsFor(PromotionRun $run): array
    {
        return $this->placeholders->collect($this->sourceResolver->resolve($run));
    }

    public function refusalFor(User $actor, PromotionRun $run): ?string
    {
        $refusal = $this->prepareConfigImport->refusalFor($actor);

        if ($refusal !== null || $run->status === PromotionRunStatus::Draft) {
            return $refusal;
        }

        return __($this->notDraftReason);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, PromotionRun $run, array $input): void
    {
        $refusal = $this->prepareConfigImport->refusalFor($actor);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        DB::transaction(function () use ($run, $input): void {
            $locked = PromotionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PromotionRunStatus::Draft) {
                throw ValidationException::withMessages(['targets' => __($this->notDraftReason)]);
            }

            $bundle = $this->sourceResolver->resolve($locked);
            $requirements = $this->placeholders->collect($bundle);

            if ($requirements === []) {
                return;
            }

            $validated = Validator::make(
                $input,
                $this->rules($requirements),
                [],
                $this->attributes($requirements, $input),
            )->validate();

            /** @var list<array{key: string, target_url: string}> $targets */
            $targets = $validated['targets'];

            $this->serializer->writeTo(
                $this->placeholders->resolve($bundle, array_column($targets, 'target_url', 'key')),
                Storage::disk('local')->path($this->sourceResolver->directoryFor($locked)),
            );
        });
    }

    /**
     * @param  list<PlaceholderRequirement>  $requirements
     * @return array<string, mixed>
     */
    private function rules(array $requirements): array
    {
        return [
            'targets' => ['required', 'list', 'size:'.count($requirements)],
            'targets.*' => ['array'],
            'targets.*.key' => [
                'required',
                'string',
                'distinct',
                Rule::in(array_map(static fn (PlaceholderRequirement $requirement): string => $requirement->artifactKey, $requirements)),
            ],
            'targets.*.target_url' => ['required', 'url', "max:{$this->maxTargetLength}"],
        ];
    }

    /**
     * @param  list<PlaceholderRequirement>  $requirements
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function attributes(array $requirements, array $input): array
    {
        $labelsByKey = [];

        foreach ($requirements as $requirement) {
            $labelsByKey[$requirement->artifactKey] = $requirement->label;
        }

        $attributes = ['targets' => __('i18n.backend.actions.config_bundle.resolve_import_placeholders_action.webhook_destinations')];
        $submitted = is_array($input['targets'] ?? null) ? $input['targets'] : [];

        foreach (array_keys($submitted) as $index) {
            $key = is_array($submitted[$index]) ? ($submitted[$index]['key'] ?? null) : null;

            $attributes["targets.{$index}.key"] = __('i18n.backend.actions.config_bundle.resolve_import_placeholders_action.webhook');
            $attributes["targets.{$index}.target_url"] = is_string($key) && isset($labelsByKey[$key]) ? $labelsByKey[$key] : __('i18n.backend.actions.config_bundle.resolve_import_placeholders_action.webhook_destination');
        }

        return $attributes;
    }
}
