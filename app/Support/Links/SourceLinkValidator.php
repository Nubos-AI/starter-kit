<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Contracts\Links\ActivitySourceRuleInterface;
use App\Models\CustomRecord;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SourceLinkValidator
{
    public function __construct(private readonly SourceLinkRegistry $registry) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(bool $isRequired = false): array
    {
        return [
            'source_type' => [
                $isRequired ? 'required' : 'nullable',
                'required_with:source_id',
                'string',
                Rule::in($this->registry->keys()),
            ],
            'source_id' => ['nullable', 'required_with:source_type', 'string', 'ulid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertAccessible(?User $user, array $validated): void
    {
        $sourceType = $validated['source_type'] ?? null;
        $sourceId = $validated['source_id'] ?? null;

        if (!is_string($sourceType) || !is_string($sourceId)) {
            return;
        }

        if ($user instanceof User && $this->registry->resolverFor($sourceType)->isAccessible($user, $sourceId)) {
            return;
        }

        throw ValidationException::withMessages([
            'source_id' => __('i18n.backend.support.links.source_link_validator.the_source_is_not_accessible'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertChangedSourceAccessible(?User $user, array $validated, ?string $currentType, ?string $currentId): void
    {
        if ($this->isUnchanged($validated, $currentType, $currentId)) {
            return;
        }

        $this->assertAccessible($user, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertChangedActivityTargetAllowed(User $user, array $validated, ?CustomRecord $record, ?string $currentType, ?string $currentId): void
    {
        $sourceType = $validated['source_type'] ?? null;
        $sourceId = $validated['source_id'] ?? null;

        if (!is_string($sourceType) || !is_string($sourceId) || $this->isUnchanged($validated, $currentType, $currentId)) {
            return;
        }

        $resolver = $this->registry->resolverFor($sourceType);

        if (!$resolver instanceof ActivitySourceRuleInterface) {
            return;
        }

        $refusal = $resolver->activityRefusal($user, $sourceId, $record);

        if ($refusal === null) {
            return;
        }

        throw ValidationException::withMessages(['source_id' => $refusal]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function isUnchanged(array $validated, ?string $currentType, ?string $currentId): bool
    {
        return ($validated['source_type'] ?? $currentType) === $currentType
            && ($validated['source_id'] ?? $currentId) === $currentId;
    }
}
