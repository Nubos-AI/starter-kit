<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Policies\Users\UserPolicy;
use App\Support\Authorization\CurrentTeamResolver;
use App\Traits\Authorization\HasRoles;
use App\Traits\Modules\HasModuleAttributes;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property Salutation $salutation
 * @property UserStatus $status
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $email
 * @property bool $is_service
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $invited_by_id
 * @property string|null $invitation_token_hash
 * @property Carbon|null $invitation_sent_at
 * @property Carbon|null $invitation_expires_at
 * @property Carbon|null $invitation_accepted_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $current_team_id
 * @property string|null $default_dashboard_id
 * @property string|null $timezone
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $name
 */
#[Appends(['name'])]
#[UsePolicy(UserPolicy::class)]
#[Fillable(['tenant_id', 'current_team_id', 'default_dashboard_id', 'status', 'salutation', 'first_name', 'last_name', 'email', 'is_service', 'password', 'timezone', 'quiet_hours_start', 'quiet_hours_end', 'invited_by_id', 'invitation_token_hash', 'invitation_sent_at', 'invitation_expires_at', 'invitation_accepted_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser, PermissionHolderInterface
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasPushSubscriptions;
    use HasRoles;
    use HasUlids;
    use HasModuleAttributes;
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salutation' => Salutation::class,
            'status' => UserStatus::class,
            'is_service' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->first_name} {$this->last_name}"),
        );
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this
            ->belongsToMany(Team::class)
            ->withTimestamps();
    }

    /**
     * @return Collection<int, Role>
     */
    protected function inheritedRolesFor(?Model $scope = null): Collection
    {
        $team = app(CurrentTeamResolver::class)->resolve($this);
        $key = $this->scopeSignature($scope).'|'.($team?->getKey() ?? '');

        if (isset($this->resolvedInheritance[$key])) {
            return $this->resolvedInheritance[$key];
        }

        return $this->resolvedInheritance[$key] = $team === null
            ? collect()
            : $team->assignedRolesFor($scope);
    }
}
