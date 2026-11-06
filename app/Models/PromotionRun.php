<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Policies\Promotion\PromotionRunPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\PromotionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $source_tenant_id
 * @property string $triggered_by_id
 * @property string|null $snapshot_group_id
 * @property string $counterpart_key
 * @property PromotionDirection $direction
 * @property PromotionRunStatus $status
 * @property array<string, mixed> $selection
 * @property array<string, mixed> $conflict_decisions
 * @property array<string, mixed>|null $report
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read PromotionSelection $promotion_selection
 * @property-read PromotionSelection $hand_selection
 * @property-read PromotionSelection $overwrite_selection
 * @property-read list<ConflictDecision> $conflict_decision_list
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(PromotionRunPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'source_tenant_id',
        'triggered_by_id',
        'snapshot_group_id',
        'counterpart_key',
        'direction',
        'status',
        'selection',
        'conflict_decisions',
        'report',
        'started_at',
        'finished_at',
    ]
)]
class PromotionRun extends Model
{
    /** @use HasFactory<PromotionRunFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function sourceTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'source_tenant_id');
    }

    /**
     * @return MorphOne<ApprovalProcess, $this>
     */
    public function approvalProcess(): MorphOne
    {
        return $this->morphOne(ApprovalProcess::class, 'anchor')->latestOfMany('created_at');
    }

    /**
     * @return Attribute<array<array-key, mixed>, never>
     */
    protected function selection(): Attribute
    {
        return Attribute::make(get: static fn (mixed $value): array => self::inContractOrder($value, ['kind', 'key', 'pulled_in', 'overwrite']));
    }

    /**
     * @return Attribute<array<array-key, mixed>, never>
     */
    protected function conflictDecisions(): Attribute
    {
        return Attribute::make(get: static fn (mixed $value): array => self::inContractOrder($value, ['kind', 'key', 'resolution', 'decided_by_id', 'decided_at']));
    }

    /**
     * @return Attribute<PromotionSelection, never>
     */
    protected function promotionSelection(): Attribute
    {
        return Attribute::make(get: fn (): PromotionSelection => $this->storedSelection(false))->withoutObjectCaching();
    }

    /**
     * @return Attribute<PromotionSelection, never>
     */
    protected function handSelection(): Attribute
    {
        return Attribute::make(get: fn (): PromotionSelection => $this->storedSelection(true))->withoutObjectCaching();
    }

    /**
     * @return Attribute<PromotionSelection, never>
     */
    protected function overwriteSelection(): Attribute
    {
        return Attribute::make(get: function (): PromotionSelection {
            $selection = new PromotionSelection([]);

            foreach ($this->selection as $pair) {
                if (data_get($pair, 'overwrite') === true) {
                    $selection = $selection->withAdded(ArtifactKind::from((string) data_get($pair, 'kind')), (string) data_get($pair, 'key'));
                }
            }

            return $selection;
        })->withoutObjectCaching();
    }

    /**
     * @return Attribute<list<ConflictDecision>, never>
     */
    protected function conflictDecisionList(): Attribute
    {
        return Attribute::make(get: fn (): array => array_values(array_map(
            static fn (mixed $decision): ConflictDecision => ConflictDecision::fromArray((array) $decision),
            $this->conflict_decisions,
        )))->withoutObjectCaching();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => PromotionDirection::class,
            'status' => PromotionRunStatus::class,
            'selection' => 'array',
            'conflict_decisions' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  list<string>  $leadingKeys
     * @return array<array-key, mixed>
     */
    private static function inContractOrder(mixed $value, array $leadingKeys): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($decoded)) {
            return [];
        }

        return array_map(
            static fn (mixed $entry): mixed => is_array($entry)
                ? array_replace(array_intersect_key(array_flip($leadingKeys), $entry), $entry)
                : $entry,
            $decoded,
        );
    }

    private function storedSelection(bool $withoutPulledIn): PromotionSelection
    {
        $selection = new PromotionSelection([]);

        foreach ($this->selection as $pair) {
            if ($withoutPulledIn && data_get($pair, 'pulled_in') === true) {
                continue;
            }

            $selection = $selection->withAdded(ArtifactKind::from((string) data_get($pair, 'kind')), (string) data_get($pair, 'key'));
        }

        return $selection;
    }
}
