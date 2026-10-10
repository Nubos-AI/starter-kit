<?php

declare(strict_types=1);

namespace App\Policies\Reports;

use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;

class ReportPolicy
{
    public function __construct(private readonly TenantBoundary $tenantBoundary) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Report $report): bool
    {
        return $this->tenantBoundary->admits($user, $report)
            && $this->mayReportOn($user, $report->objectType);
    }

    public function create(User $user, ?ObjectType $objectType = null): bool
    {
        return !$objectType instanceof ObjectType || $this->mayReportOn($user, $objectType);
    }

    public function update(User $user, Report $report): bool
    {
        return $this->view($user, $report) && $this->mayGovern($user, $report);
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->view($user, $report) && $this->mayGovern($user, $report);
    }

    private function mayReportOn(User $user, ?ObjectType $objectType): bool
    {
        return $objectType instanceof ObjectType && $user->hasPermission("{$objectType->slug}.view");
    }

    private function mayGovern(User $user, Report $report): bool
    {
        return $report->owner_id === $user->getKey() || $user->isEscalatedAuthority();
    }
}
