export interface MaintenanceState {
    tenantName: string;
    since: string;
    reason: string;
}

export interface MaintenanceLockDetails {
    id: string;
    reason: string;
    reasonLabel: string;
    note: string | null;
    acquiredAt: string;
    acquiredByName?: string | null;
}
