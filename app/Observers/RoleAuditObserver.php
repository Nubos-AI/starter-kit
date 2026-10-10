<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Role;
use App\Support\Audit\AdminArtifactAuditor;

class RoleAuditObserver
{
    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    public function created(Role $role): void
    {
        $this->auditor->recordCreated($role);
    }

    public function updated(Role $role): void
    {
        $this->auditor->recordUpdated($role);
    }

    public function deleted(Role $role): void
    {
        $this->auditor->recordDeleted($role);
    }
}
