<?php

declare(strict_types=1);

namespace App\Policies\Reports;

use App\Enums\Reports\ReportExecutionMode;
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

    public function export(User $user, Report $report): bool
    {
        $objectType = $report->objectType;

        return $objectType instanceof ObjectType
            && $this->view($user, $report)
            && $user->hasPermission("{$objectType->slug}.export");
    }

    public function create(User $user, ?ObjectType $objectType = null): bool
    {
        return !$objectType instanceof ObjectType || $this->mayReportOn($user, $objectType);
    }

    public function update(User $user, Report $report): bool
    {
        return $this->view($user, $report)
            && ($this->isOpenSystemReport($report) || $this->mayGovern($user, $report));
    }

    public function delete(User $user, Report $report): bool
    {
        return !$report->is_system
            && $this->view($user, $report)
            && $this->mayGovern($user, $report);
    }

    private function isOpenSystemReport(Report $report): bool
    {
        return $report->is_system && $report->execution_mode !== ReportExecutionMode::Definer;
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
