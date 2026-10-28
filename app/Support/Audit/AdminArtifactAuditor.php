<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditEntry;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class AdminArtifactAuditor
{
    /**
     * @var list<string>
     */
    private array $ignored = ['id', 'created_at', 'updated_at'];

    public function __construct(private readonly ActorResolver $actor) {}

    public function recordCreated(Model $auditable, ?string $tenantId = null): void
    {
        $this->record($auditable, [], $auditable->getAttributes(), $tenantId);
    }

    public function recordUpdated(Model $auditable, ?string $tenantId = null): void
    {
        $changes = $auditable->getChanges();

        $old = [];

        foreach (array_keys($changes) as $key) {
            $old[$key] = $auditable->getOriginal($key);
        }

        $this->record($auditable, $old, $changes, $tenantId);
    }

    public function recordDeleted(Model $auditable, ?string $tenantId = null): void
    {
        $this->record($auditable, $auditable->getOriginal(), [], $tenantId);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     *
     * @throws JsonException
     */
    public function record(Model $auditable, array $old, array $new, ?string $tenantId = null): void
    {
        $tenantId ??= TenantContext::currentId();

        if ($tenantId === null) {
            return;
        }

        $changes = $this->diff($old, $new);

        if ($changes === []) {
            return;
        }

        $version = $this->nextVersion($auditable);
        [$actorId, $actorType] = $this->actor->resolve();
        $changedAt = now();

        $rows = [];

        foreach ($changes as $change) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'auditable_type' => $auditable->getMorphClass(),
                'auditable_id' => (string) $auditable->getKey(),
                'field_key' => $change['field_key'],
                'old_value' => $this->encode($change['old']),
                'new_value' => $this->encode($change['new']),
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'version' => $version,
                'changed_at' => $changedAt,
            ];
        }

        AuditEntry::query()->insert($rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function recordEvent(Model $auditable, string $eventKey, array $payload = [], ?string $tenantId = null): void
    {
        $tenantId ??= TenantContext::currentId();

        if ($tenantId === null) {
            return;
        }

        [$actorId, $actorType] = $this->actor->resolve();

        AuditEntry::query()->insert([
            [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'auditable_type' => $auditable->getMorphClass(),
                'auditable_id' => (string) $auditable->getKey(),
                'field_key' => $eventKey,
                'old_value' => null,
                'new_value' => $this->encode($payload),
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'version' => $this->nextVersion($auditable),
                'changed_at' => now(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{field_key: string, old: mixed, new: mixed}>
     */
    private function diff(array $old, array $new): array
    {
        $keys = array_diff(array_keys($old + $new), $this->ignored);

        $changes = [];

        foreach ($keys as $key) {
            $oldValue = $this->normalize($old[$key] ?? null);
            $newValue = $this->normalize($new[$key] ?? null);

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field_key' => $key,
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    private function normalize(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    protected function nextVersion(Model $auditable): int
    {
        $max = DB::table('audit_entries')
            ->where('auditable_type', $auditable->getMorphClass())
            ->where('auditable_id', (string) $auditable->getKey())
            ->max('version');

        return (int) $max + 1;
    }

    /**
     * @throws JsonException
     */
    private function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
