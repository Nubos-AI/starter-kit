<?php

declare(strict_types=1);

namespace App\Traits\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Models\ObjectType;
use App\Support\ConfigBundle\ArtifactKindRegistry;

trait ResolvesArtifactTargets
{
    private function objectTypeOf(string $kind, string $businessKey): ?ObjectType
    {
        $id = $this->targetKeys->parentIdFor($kind, $businessKey, 'object_type_id');

        return $id === null ? null : ObjectType::query()->whereKey($id)->first();
    }

    private function localNameOf(string $businessKey, string $parent = ArtifactKind::ObjectTypes->value): string
    {
        $prefix = $this->targetKeys->knownPrefixOf($parent, $businessKey);

        if ($prefix !== null) {
            return mb_substr($businessKey, mb_strlen($prefix) + 1);
        }

        $components = explode(':', $businessKey);

        return end($components);
    }

    private function invalidated(string $kind, ArtifactWriteResult $result): ArtifactWriteResult
    {
        if ($result->action !== ArtifactWriteAction::Skipped) {
            $this->targetKeys->invalidate($kind);
        }

        return $result;
    }

    private function resolvedDeeply(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->resolvedDeeply($item), $value);
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        return $this->targetIdOf($value) ?? $value;
    }

    private function targetIdOf(string $businessKey): ?string
    {
        foreach (ArtifactKindRegistry::all() as $kind) {
            $id = $this->targetKeys->idFor($kind, $businessKey);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }
}
