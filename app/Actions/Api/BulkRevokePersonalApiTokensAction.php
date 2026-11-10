<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class BulkRevokePersonalApiTokensAction extends BulkDeleteAction
{
    public function __construct(private readonly RevokeApiTokenAction $revokeApiToken) {}

    /**
     * @return Builder<PersonalAccessToken>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey());
    }

    /**
     * @param  PersonalAccessToken  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->revokeApiToken->execute($actor, (string) $model->getKey());
    }

    /**
     * @return list<string>
     */
    protected function identifierRules(): array
    {
        return ['integer'];
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return true;
    }
}
