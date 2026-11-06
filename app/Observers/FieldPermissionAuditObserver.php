<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FieldPermission;
use App\Support\Audit\AdminArtifactAuditor;

class FieldPermissionAuditObserver
{
    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    public function created(FieldPermission $permission): void
    {
        $this->auditor->recordCreated($permission);
    }

    public function updated(FieldPermission $permission): void
    {
        $this->auditor->recordUpdated($permission);
    }

    public function deleted(FieldPermission $permission): void
    {
        $this->auditor->recordDeleted($permission);
    }
}
