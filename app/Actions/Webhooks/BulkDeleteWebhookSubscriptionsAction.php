<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteWebhookSubscriptionsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteWebhookSubscriptionAction $deleteSubscription) {}

    /**
     * @return Builder<WebhookSubscription>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return WebhookSubscription::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  WebhookSubscription  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteSubscription->execute($model);
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return true;
    }
}
