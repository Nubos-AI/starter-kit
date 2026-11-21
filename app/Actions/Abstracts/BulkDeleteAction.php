<?php

declare(strict_types=1);

namespace App\Actions\Abstracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

abstract class BulkDeleteAction
{
    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, Model>
     *
     * @throws Throwable
     */
    public function execute(User $actor, array $input, ?Model $scope = null): Collection
    {
        $validated = Validator::make($input, [
            'ids' => ['array'],
            'ids.*' => $this->identifierRules(),
        ])->validate();

        $ids = is_array($validated['ids'] ?? null) ? $validated['ids'] : [];

        $deletable = [];

        $query = $this->query($actor, $scope);
        $query->whereKey($ids);

        foreach ($query->applyScopes()->getModels() as $model) {
            if ($this->mayDelete($actor, $model)) {
                $deletable[] = $model;
            }
        }

        DB::transaction(function () use ($actor, $deletable): void {
            foreach ($deletable as $model) {
                $this->deleteOne($actor, $model);
            }
        });

        return new Collection($deletable);
    }

    /**
     * @return Builder<covariant Model>
     */
    abstract protected function query(User $actor, ?Model $scope): Builder;

    abstract protected function deleteOne(User $actor, Model $model): void;

    /**
     * @return list<string>
     */
    protected function identifierRules(): array
    {
        return ['string'];
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return $actor->can('delete', $model);
    }
}
