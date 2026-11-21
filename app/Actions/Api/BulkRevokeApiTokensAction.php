<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\User;
use App\Support\Api\ApiTokenRevocationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class BulkRevokeApiTokensAction extends BulkDeleteAction
{
    public function __construct(
        private readonly RevokeApiTokenAction $revokeApiToken,
        private readonly ApiTokenRevocationGuard $revocationGuard,
    ) {}

    /**
     * @return Builder<PersonalAccessToken>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn(
                'tokenable_id',
                User::query()->where('tenant_id', $actor->tenant_id)->pluck('id')
            );
    }

    /**
     * @param  PersonalAccessToken  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $owner = $model->tokenable;

        if ($owner instanceof User) {
            $this->revokeApiToken->execute($owner, (string) $model->getKey());
        }
    }

    /**
     * @return list<string>
     */
    protected function identifierRules(): array
    {
        return ['integer'];
    }

    /**
     * @param  PersonalAccessToken  $model
     */
    protected function mayDelete(User $actor, Model $model): bool
    {
        $owner = $model->tokenable;

        return $owner instanceof User && $this->revocationGuard->mayRevoke($actor, $owner);
    }
}
