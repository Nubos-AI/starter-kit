import type { RolePayload } from '@/types/roles';

export interface RolePermission {
    id: string;
    name: string;
    group: string | null;
}

export interface AssignableRole extends RolePayload {
    can_assign: boolean;
    permissions?: RolePermission[];
}

export interface UserRow {
    status_url?: string;
    id: string;
    name: string;
    email: string;
    status: string;
    role_ids: string[];
    is_escalated: boolean;
    can_update: boolean;
    can_delete: boolean;
    delete_reason: string | null;
    can_block: boolean;
    block_reason: string | null;
    can_resend_invitation: boolean;
}

export interface UserFormPayload {
    id: string;
    salutation: string;
    first_name: string | null;
    last_name: string | null;
    email: string;
    role_ids: string[];
    team_ids: string[];
    denied_permission_ids: string[];
    status: string;
    can_update_password: boolean;
    can_manage_access: boolean;
}

export interface SalutationOption {
    value: string;
    label: string;
}
