<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Links\SourceLinkableInterface;
use App\Contracts\Recurrence\RecurringInterface;
use App\Enums\Recurrence\RecurrenceFrequency;
use App\Observers\ReminderTaskWatcherObserver;
use App\Scopes\TenantScope;
use App\Traits\Recurrence\HasRecurrenceRule;
use App\Traits\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\ReminderTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_id
 * @property string $creator_id
 * @property string|null $assignee_id
 * @property string|null $record_id
 * @property string|null $reminder_type_id
 * @property string|null $series_id
 * @property string|null $source_type
 * @property string|null $source_id
 * @property Carbon|null $due_at
 * @property string $subject
 * @property string|null $note
 * @property RecurrenceFrequency|null $recurrence_frequency
 * @property int|null $recurrence_interval
 * @property Carbon|null $recurrence_ends_at
 * @property Carbon|null $recurrence_anchor_at
 * @property list<string>|null $recurrence_exceptions
 * @property Carbon|null $series_occurrence_at
 * @property Carbon|null $done_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy([ReminderTaskWatcherObserver::class])]
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'owner_id',
        'creator_id',
        'assignee_id',
        'record_id',
        'reminder_type_id',
        'series_id',
        'source_type',
        'source_id',
        'due_at',
        'subject',
        'note',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_ends_at',
        'recurrence_anchor_at',
        'recurrence_exceptions',
        'series_occurrence_at',
        'done_at',
        'notified_at',
    ]
)]
class ReminderTask extends Model implements RecurringInterface, SourceLinkableInterface
{
    /** @use HasFactory<ReminderTaskFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasRecurrenceRule;
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class);
    }

    /**
     * @return BelongsTo<ReminderType, $this>
     */
    public function reminderType(): BelongsTo
    {
        return $this->belongsTo(ReminderType::class);
    }

    /**
     * @return BelongsTo<ReminderTask, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(self::class, 'series_id');
    }

    /**
     * @param  Builder<ReminderTask>  $query
     * @return Builder<ReminderTask>
     */
    #[Scope]
    protected function openForUser(Builder $query, User $user): Builder
    {
        return $query
            ->where('assignee_id', $user->getKey())
            ->whereNull('done_at')
            ->orderBy('due_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'recurrence_frequency' => RecurrenceFrequency::class,
            'recurrence_interval' => 'integer',
            'recurrence_ends_at' => 'datetime',
            'recurrence_anchor_at' => 'datetime',
            'recurrence_exceptions' => 'array',
            'series_occurrence_at' => 'datetime',
            'done_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function seriesStart(): ?CarbonInterface
    {
        return $this->due_at;
    }

    public function seriesAnchor(): ?CarbonInterface
    {
        return $this->recurrence_anchor_at ?? $this->due_at;
    }

    public function linkedRecordId(): ?string
    {
        return $this->record_id;
    }

    public function linkedSourceType(): ?string
    {
        return $this->source_type;
    }

    public function linkedSourceId(): ?string
    {
        return $this->source_id;
    }
}
