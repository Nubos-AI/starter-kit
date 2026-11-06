export type RoleScopeValue = 'platform' | 'tenant' | 'team';

export type RoleAuthorityValue = 'super_admin' | 'scope_admin';

export type RoleFormMode = 'create' | 'edit';

export interface RoleOption {
    value: string;
    label: string;
}

export interface RolePayload {
    id: string;
    name: string;
    scope: RoleScopeValue;
    authority: RoleAuthorityValue | null;
    is_system: boolean;
    grants_subteam_visibility: boolean;
}

export interface RoleRow extends RolePayload {
    can_update: boolean;
    can_delete: boolean;
    user_count: number;
}

export interface RolePermissionItem {
    id: string;
    name: string;
}

export interface RolePermissionGroup {
    key: string;
    label: string;
    permissions: RolePermissionItem[];
}

export interface RolePermissionTab {
    key: string;
    label: string;
    type: 'direct' | 'dropdown';
    groups: RolePermissionGroup[];
}

export interface RoleFormPermissions {
    assigned: string[];
    tabs: RolePermissionTab[];
}
