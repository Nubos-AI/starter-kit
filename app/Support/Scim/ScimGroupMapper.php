<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimGroupChanges;
use App\DTOs\Scim\ScimPatchOperationData;
use App\Enums\Scim\ScimPatchOperation;
use App\Exceptions\Scim\ScimException;
use Illuminate\Support\Str;

class ScimGroupMapper
{
    private string $memberFilterPattern = '/^members\[\s*value\s+eq\s+"([^"]+)"\s*\]$/i';

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function fromResource(array $body): ScimGroupChanges
    {
        return new ScimGroupChanges($this->displayName($body['displayName'] ?? null), $this->memberIds($body['members'] ?? []), [], []);
    }

    /**
     * @param  list<ScimPatchOperationData>  $operations
     *
     * @throws ScimException
     */
    public function fromPatch(array $operations): ScimGroupChanges
    {
        $state = ['displayName' => null, 'replace' => null, 'add' => [], 'remove' => []];

        foreach ($operations as $operation) {
            $state = $operation->operation === ScimPatchOperation::Remove
                ? $this->applyRemoval($state, $operation)
                : $this->applyAssignments($state, $operation);
        }

        return new ScimGroupChanges(
            $state['displayName'],
            $state['replace'],
            array_values(array_unique($state['add'])),
            array_values(array_unique($state['remove'])),
        );
    }

    /**
     * @param  array{displayName: string|null, replace: list<string>|null, add: list<string>, remove: list<string>}  $state
     * @return array{displayName: string|null, replace: list<string>|null, add: list<string>, remove: list<string>}
     */
    private function applyRemoval(array $state, ScimPatchOperationData $operation): array
    {
        $path = (string) $operation->path;

        if (preg_match($this->memberFilterPattern, $path, $matches) === 1) {
            return [...$state, 'remove' => [...$state['remove'], $matches[1]]];
        }

        if (Str::lower($path) !== 'members') {
            return $state;
        }

        return $operation->value === null
            ? [...$state, 'replace' => []]
            : [...$state, 'remove' => [...$state['remove'], ...$this->memberIds($operation->value)]];
    }

    /**
     * @param  array{displayName: string|null, replace: list<string>|null, add: list<string>, remove: list<string>}  $state
     * @return array{displayName: string|null, replace: list<string>|null, add: list<string>, remove: list<string>}
     */
    private function applyAssignments(array $state, ScimPatchOperationData $operation): array
    {
        $values = match (true) {
            $operation->path !== null => [$operation->path => $operation->value],
            is_array($operation->value) => $operation->value,
            default => throw ScimException::invalidSyntax(),
        };

        foreach ($values as $attribute => $value) {
            $state = match (Str::lower((string) $attribute)) {
                'displayname' => [...$state, 'displayName' => $this->displayName($value)],
                'members' => $operation->operation === ScimPatchOperation::Add
                    ? [...$state, 'add' => [...$state['add'], ...$this->memberIds($value)]]
                    : [...$state, 'replace' => $this->memberIds($value)],
                default => $state,
            };
        }

        return $state;
    }

    private function displayName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_group_mapper.display_name_required'));
        }

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function memberIds(mixed $members): array
    {
        if (!is_array($members)) {
            throw ScimException::invalidSyntax();
        }

        $ids = [];

        foreach ($members as $member) {
            $id = is_array($member) ? ($member['value'] ?? null) : null;

            if (!is_string($id) || $id === '') {
                throw ScimException::invalidSyntax();
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
