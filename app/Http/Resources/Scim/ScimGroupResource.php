<?php

declare(strict_types=1);

namespace App\Http\Resources\Scim;

use App\Enums\Scim\ScimSchema;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class ScimGroupResource extends JsonResource
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
        /** @var Team $team */
        $team = $this->resource;
        $id = (string) $team->getKey();
        $lastModified = $team->updated_at?->toIso8601String();
        $hash = sha1("{$id}|{$lastModified}");

        return [
            'schemas' => [ScimSchema::Group->value],
            'id' => $id,
            'displayName' => $team->name,
            'members' => $team->relationLoaded('users')
                ? $team->users->map(static fn (User $user): array => [
                    'value' => (string) $user->getKey(),
                    'display' => $user->name !== '' ? $user->name : $user->email,
                    '$ref' => url("/scim/v2/Users/{$user->getKey()}"),
                ])->values()->all()
                : [],
            'meta' => [
                'resourceType' => 'Group',
                'created' => $team->created_at?->toIso8601String(),
                'lastModified' => $lastModified,
                'location' => url("/scim/v2/Groups/{$id}"),
                'version' => "W/\"{$hash}\"",
            ],
        ];
    }
}
