<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Enums\Audit\ActorType;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

class FieldEditorResolver
{
    /**
     * @param  list<string>  $fieldKeys
     */
    public function lastEditorOfFields(CustomRecord $record, array $fieldKeys): ?string
    {
        if ($fieldKeys === []) {
            return null;
        }

        $actorId = $this->humanAuditRowsOf($record)
            ->whereIn('field_key', $fieldKeys)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->value('actor_id');

        return $actorId === null ? null : (string) $actorId;
    }

    public function creatorOf(CustomRecord $record): ?string
    {
        $actorId = $this->humanAuditRowsOf($record)
            ->where('version', 1)
            ->where('field_key', '!=', 'deleted_at')
            ->orderBy('changed_at')
            ->orderBy('id')
            ->value('actor_id');

        return $actorId === null ? null : (string) $actorId;
    }

    /**
     * @return Builder<AuditEntry>
     */
    private function humanAuditRowsOf(CustomRecord $record): Builder
    {
        return AuditEntry::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('audit_entries.tenant_id', $record->tenant_id)
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', (string) $record->getKey())
            ->where('actor_type', ActorType::User->value)
            ->whereNotNull('actor_id');
    }
}
