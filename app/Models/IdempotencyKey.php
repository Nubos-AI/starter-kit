<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Api\IdempotencyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $access_token_id
 * @property string $idempotency_key
 * @property string $request_body_hash
 * @property IdempotencyStatus $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon $locked_at
 * @property Carbon $created_at
 */
class IdempotencyKey extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_body' => 'array',
            'response_status' => 'integer',
            'locked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
