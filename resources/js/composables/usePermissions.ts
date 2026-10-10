import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import type {
    GlobalPermission,
    ObjectTypeAbility,
    PermissionMap,
} from '@/types/permissions';
import type { RoleAuthorityValue } from '@/types/roles';

const AUTHORITY_VALUES: readonly RoleAuthorityValue[] = [
    'super_admin',
    'scope_admin',
];

export interface UsePermissionsReturn<ModulePermission extends string = never> {
    can: (permission: GlobalPermission | ModulePermission) => boolean;
    canForObjectType: (slug: string, ability: ObjectTypeAbility) => boolean;
    isEscalated: ComputedRef<boolean>;
    authority: ComputedRef<RoleAuthorityValue | null>;
}

function readAuth(props: unknown): { can?: unknown; authority?: unknown } {
    if (props === null || typeof props !== 'object') {
        return {};
    }

    const auth = (props as { auth?: unknown }).auth;

    return auth === null || typeof auth !== 'object' ? {} : auth;
}

function readMap(raw: unknown): PermissionMap {
    return raw === null || typeof raw !== 'object' || Array.isArray(raw)
        ? {}
        : (raw as PermissionMap);
}

function readAuthority(raw: unknown): RoleAuthorityValue | null {
    return AUTHORITY_VALUES.find((value) => value === raw) ?? null;
}

export function usePermissions<
    ModulePermission extends string = never,
>(): UsePermissionsReturn<ModulePermission> {
    const page = usePage();

    const map = (): PermissionMap => readMap(readAuth(page?.props).can);

    const authority = computed<RoleAuthorityValue | null>(() =>
        readAuthority(readAuth(page?.props).authority),
    );

    const isEscalated = computed<boolean>(() => authority.value !== null);

    return {
        can: (permission) => map()[permission] === true,
        canForObjectType: (slug, ability) =>
            map()[`${slug}.${ability}`] === true,
        isEscalated,
        authority,
    };
}
