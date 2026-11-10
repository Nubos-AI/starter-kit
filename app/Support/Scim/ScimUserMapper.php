<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimPatchOperationData;
use App\DTOs\Scim\ScimUserChanges;
use App\Enums\Scim\ScimPatchOperation;
use App\Exceptions\Scim\ScimException;
use Illuminate\Support\Str;

class ScimUserMapper
{
    private string $emailValuePathPattern = '/^emails(\[[^\]]*\])?\.value$/';

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function fromResource(array $body): ScimUserChanges
    {
        $email = $this->email($body['userName'] ?? null) ?? $this->emailOfList($body['emails'] ?? null);

        if ($email === null) {
            throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.email_required'));
        }

        $name = is_array($body['name'] ?? null) ? $body['name'] : [];

        return new ScimUserChanges([
            'email' => $email,
            'first_name' => $this->text($name['givenName'] ?? null),
            'last_name' => $this->text($name['familyName'] ?? null),
            'is_active' => array_key_exists('active', $body) ? $this->boolean($body['active']) : true,
            'external_id' => $this->text($body['externalId'] ?? null),
        ]);
    }

    /**
     * @param  list<ScimPatchOperationData>  $operations
     *
     * @throws ScimException
     */
    public function fromPatch(array $operations): ScimUserChanges
    {
        $values = [];

        foreach ($operations as $operation) {
            if ($operation->operation === ScimPatchOperation::Remove) {
                $values = $this->remove($values, Str::lower((string) $operation->path));

                continue;
            }

            if ($operation->path !== null) {
                $values = $this->assign($values, Str::lower($operation->path), $operation->value);

                continue;
            }

            if (!is_array($operation->value)) {
                throw ScimException::invalidSyntax();
            }

            foreach ($operation->value as $attribute => $value) {
                $values = $this->assign($values, Str::lower((string) $attribute), $value);
            }
        }

        /** @var array{email?: string, first_name?: string|null, last_name?: string|null, is_active?: bool, external_id?: string|null} $values */
        return new ScimUserChanges($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function assign(array $values, string $path, mixed $value): array
    {
        if ($path === 'emails' || preg_match($this->emailValuePathPattern, $path) === 1) {
            $email = $path === 'emails' ? $this->emailOfList($value) : $this->email($value);

            return [...$values, 'email' => $email ?? throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.email_required'))];
        }

        return match ($path) {
            'active' => [...$values, 'is_active' => $this->boolean($value)],
            'username' => [...$values, 'email' => $this->email($value) ?? throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.email_required'))],
            'externalid' => [...$values, 'external_id' => $this->text($value)],
            'name.givenname' => [...$values, 'first_name' => $this->text($value)],
            'name.familyname' => [...$values, 'last_name' => $this->text($value)],
            'name' => is_array($value) ? $this->assignName($values, $value) : throw ScimException::invalidSyntax(),
            default => $values,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<mixed>  $name
     * @return array<string, mixed>
     */
    private function assignName(array $values, array $name): array
    {
        if (array_key_exists('givenName', $name)) {
            $values['first_name'] = $this->text($name['givenName']);
        }

        if (array_key_exists('familyName', $name)) {
            $values['last_name'] = $this->text($name['familyName']);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function remove(array $values, string $path): array
    {
        return match ($path) {
            'externalid' => [...$values, 'external_id' => null],
            'name.givenname' => [...$values, 'first_name' => null],
            'name.familyname' => [...$values, 'last_name' => null],
            'name' => [...$values, 'first_name' => null, 'last_name' => null],
            'active', 'username', 'emails' => throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.attribute_required', ['attribute' => $path])),
            default => preg_match($this->emailValuePathPattern, $path) === 1
                ? throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.attribute_required', ['attribute' => $path]))
                : $values,
        };
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_string($value) ? Str::lower($value) : null) {
            'true' => true,
            'false' => false,
            default => throw ScimException::invalidValue(__('i18n.backend.support.scim.scim_user_mapper.active_must_be_boolean')),
        };
    }

    private function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $email = Str::lower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function emailOfList(mixed $emails): ?string
    {
        if (!is_array($emails)) {
            return null;
        }

        $entries = array_values(array_filter($emails, 'is_array'));
        $primary = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['primary'] ?? false) === true));

        return $this->email(($primary[0] ?? $entries[0] ?? [])['value'] ?? null);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
