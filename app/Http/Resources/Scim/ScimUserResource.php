<?php

declare(strict_types=1);

namespace App\Http\Resources\Scim;

use App\Enums\Scim\ScimSchema;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class ScimUserResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        /** @var User $user */
        $user = $this->resource;
        $id = (string) $user->getKey();
        $externalId = $this->externalIdOf($user);

        $resource = [
            'schemas' => [ScimSchema::User->value],
            'id' => $id,
        ];

        if ($externalId !== null) {
            $resource['externalId'] = $externalId;
        }

        return [
            ...$resource,
            'userName' => $user->email,
            'displayName' => $user->name,
            'name' => [
                'givenName' => $user->first_name,
                'familyName' => $user->last_name,
                'formatted' => $user->name,
            ],
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => $user->status === UserStatus::Accepted,
            'groups' => $user->relationLoaded('teams')
                ? $user->teams->map(static fn (Team $team): array => [
                    'value' => (string) $team->getKey(),
                    'display' => $team->name,
                    '$ref' => url("/scim/v2/Groups/{$team->getKey()}"),
                ])->values()->all()
                : [],
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => url("/scim/v2/Users/{$id}"),
                'version' => $this->versionOf($id, $user->updated_at?->toIso8601String()),
            ],
        ];
    }

    private function externalIdOf(User $user): ?string
    {
        if (!$user->relationLoaded('identities')) {
            return null;
        }

        $identity = $user->identities->first(static fn (UserIdentity $identity): bool => $identity->provider === IdentityProvider::Scim);

        return $identity instanceof UserIdentity ? $identity->subject : null;
    }

    private function versionOf(string $id, ?string $lastModified): string
    {
        $hash = sha1("{$id}|{$lastModified}");

        return "W/\"{$hash}\"";
    }
}
