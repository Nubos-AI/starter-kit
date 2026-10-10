<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\Webhooks\WebhookEventType;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\ObjectType;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WebhookSubscriptionMatcher
{
    /**
     * @return Collection<int, WebhookSubscription>
     */
    public function match(string $tenantId, string $objectTypeId, WebhookEventType $type): Collection
    {
        $slug = ObjectType::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($objectTypeId)
            ->value('slug');

        if (!is_string($slug)) {
            return new Collection;
        }

        return $this->query($tenantId, $objectTypeId, $slug, $type)->get();
    }

    /**
     * @return Builder<WebhookSubscription>
     */
    public function query(string $tenantId, string $objectTypeId, string $objectTypeSlug, WebhookEventType $type): Builder
    {
        return WebhookSubscription::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', WebhookSubscriptionStatus::Active)
            ->where(function (Builder $query) use ($objectTypeId): void {
                $query->where('object_type_id', $objectTypeId)
                    ->orWhereNull('object_type_id');
            })
            ->whereJsonContains('event_types', $type->value)
            ->whereHas('role.permissions', static fn (Builder $query): Builder => $query->where('name', "{$objectTypeSlug}.view"));
    }
}
