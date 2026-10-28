import type { Ref } from 'vue';
import { onScopeDispose, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DashboardShareOptionsController from '@/actions/App/Http/Controllers/Dashboards/DashboardShareOptionsController';
import DashboardSharesController from '@/actions/App/Http/Controllers/Dashboards/DashboardSharesController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readBodyReason } from '@/lib/errorResponse';
import type { DashboardShare, ShareGranteeType } from '@/types/dashboards';
import type { SelectOption } from '@/types/ui';

const REQUEST_TIMEOUT_MS = 15000;

const REFUSED_STATUS = 422;

export const SHARES_LOAD_ERROR_MESSAGE =
    'Die Freigaben konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

export const OPTIONS_LOAD_ERROR_MESSAGE =
    'Die Empfänger konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

export const SHARE_CREATE_ERROR_MESSAGE =
    'Die Freigabe konnte nicht angelegt werden. Bitte versuchen Sie es erneut.';

export const SHARE_REVOKE_ERROR_MESSAGE =
    'Die Freigabe konnte nicht widerrufen werden. Bitte versuchen Sie es erneut.';

export const TENANT_WIDE_ERROR_MESSAGE =
    'Die mandantenweite Sichtbarkeit konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export type ShareGranteeOptions = Record<ShareGranteeType, SelectOption[]>;

export interface CreateDashboardSharePayload {
    grantee_type: ShareGranteeType;
    grantee_id: string;
    can_edit: boolean;
}

export interface UseDashboardSharesReturn {
    shares: Ref<DashboardShare[]>;
    options: Ref<ShareGranteeOptions>;
    isTenantWide: Ref<boolean>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    tenantWideError: Ref<string | null>;
    load: () => Promise<void>;
    loadOptions: () => Promise<void>;
    create: (
        payload: CreateDashboardSharePayload,
    ) => Promise<DashboardShare | null>;
    revoke: (shareId: string) => Promise<boolean>;
    setTenantWide: (next: boolean) => Promise<boolean>;
}

function emptyOptions(): ShareGranteeOptions {
    return { user: [], team: [], role: [] };
}

function readList(body: unknown): DashboardShare[] | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    return Array.isArray(body.data) ? (body.data as DashboardShare[]) : null;
}

function readShare(body: unknown): DashboardShare | null {
    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const data = body.data;

    return data === null || typeof data !== 'object' || Array.isArray(data)
        ? null
        : (data as DashboardShare);
}

function readOptions(body: unknown): ShareGranteeOptions | null {
    if (body === null || typeof body !== 'object' || !('options' in body)) {
        return null;
    }

    const delivered = body.options;

    if (delivered === null || typeof delivered !== 'object') {
        return null;
    }

    const source = delivered as Partial<Record<string, unknown>>;
    const collected = emptyOptions();

    (Object.keys(collected) as ShareGranteeType[]).forEach((key) => {
        const entries = source[key];

        if (Array.isArray(entries)) {
            collected[key] = entries as SelectOption[];
        }
    });

    return collected;
}

export function useDashboardShares(
    dashboardId: string,
    initialTenantWide: () => boolean,
): UseDashboardSharesReturn {
    const shares = ref<DashboardShare[]>([]);
    const options = ref<ShareGranteeOptions>(emptyOptions());
    const isTenantWide = ref<boolean>(initialTenantWide());
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);
    const tenantWideError = ref<string | null>(null);

    const controllers = new Set<AbortController>();

    let optionsSequence = 0;
    let failedOperation: string | null = null;
    let disposed = false;

    onScopeDispose(() => {
        disposed = true;
        controllers.forEach((controller) => controller.abort());
        controllers.clear();
    });

    watch(initialTenantWide, (next) => {
        isTenantWide.value = next;
    });

    async function request(
        url: string,
        method: string,
        payload?: Record<string, unknown>,
    ): Promise<Response | null> {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        controllers.add(controller);

        try {
            return await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: payload !== undefined }),
                body:
                    payload === undefined ? undefined : JSON.stringify(payload),
                signal: controller.signal,
            });
        } catch {
            return null;
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    }

    async function readJson(response: Response): Promise<unknown> {
        try {
            return await response.json();
        } catch {
            return null;
        }
    }

    function fail(operation: string, message: string): void {
        failedOperation = operation;
        error.value = message;
        toast.error(message);
    }

    function succeed(operation: string): void {
        if (failedOperation === operation) {
            failedOperation = null;
            error.value = null;
        }
    }

    async function load(): Promise<void> {
        loading.value = true;

        const response = await request(
            DashboardSharesController.index.url({ dashboard: dashboardId }),
            'GET',
        );

        if (disposed) {
            return;
        }

        loading.value = false;

        const delivered =
            response !== null && response.ok
                ? readList(await readJson(response))
                : null;

        if (delivered === null) {
            fail(SHARES_LOAD_ERROR_MESSAGE, SHARES_LOAD_ERROR_MESSAGE);

            return;
        }

        succeed(SHARES_LOAD_ERROR_MESSAGE);
        shares.value = delivered;
    }

    async function loadOptions(): Promise<void> {
        optionsSequence += 1;

        const current = optionsSequence;

        const response = await request(
            DashboardShareOptionsController.url({ dashboard: dashboardId }),
            'GET',
        );

        if (disposed || current !== optionsSequence) {
            return;
        }

        const delivered =
            response !== null && response.ok
                ? readOptions(await readJson(response))
                : null;

        if (delivered === null) {
            fail(OPTIONS_LOAD_ERROR_MESSAGE, OPTIONS_LOAD_ERROR_MESSAGE);

            return;
        }

        succeed(OPTIONS_LOAD_ERROR_MESSAGE);
        options.value = delivered;
    }

    async function create(
        payload: CreateDashboardSharePayload,
    ): Promise<DashboardShare | null> {
        const response = await request(
            DashboardSharesController.store.url({ dashboard: dashboardId }),
            'POST',
            { ...payload },
        );

        if (disposed) {
            return null;
        }

        const body = response === null ? null : await readJson(response);
        const created =
            response !== null && response.ok ? readShare(body) : null;

        if (created === null) {
            fail(
                SHARE_CREATE_ERROR_MESSAGE,
                readBodyReason(body) ?? SHARE_CREATE_ERROR_MESSAGE,
            );

            return null;
        }

        succeed(SHARE_CREATE_ERROR_MESSAGE);
        shares.value = [
            ...shares.value.filter((entry) => entry.id !== created.id),
            created,
        ];

        return created;
    }

    async function revoke(shareId: string): Promise<boolean> {
        const response = await request(
            DashboardSharesController.destroy.url({
                dashboard: dashboardId,
                share: shareId,
            }),
            'DELETE',
        );

        if (disposed) {
            return false;
        }

        if (response === null || !response.ok) {
            const body = response === null ? null : await readJson(response);

            fail(
                SHARE_REVOKE_ERROR_MESSAGE,
                readBodyReason(body) ?? SHARE_REVOKE_ERROR_MESSAGE,
            );

            return false;
        }

        succeed(SHARE_REVOKE_ERROR_MESSAGE);
        shares.value = shares.value.filter((entry) => entry.id !== shareId);

        return true;
    }

    async function setTenantWide(next: boolean): Promise<boolean> {
        const previous = isTenantWide.value;

        isTenantWide.value = next;
        tenantWideError.value = null;

        const response = await request(
            DashboardsController.update.url({ dashboard: dashboardId }),
            'PUT',
            { is_tenant_wide: next },
        );

        if (disposed) {
            return false;
        }

        if (response === null || !response.ok) {
            const body = response === null ? null : await readJson(response);

            isTenantWide.value = previous;
            tenantWideError.value =
                (response?.status === REFUSED_STATUS
                    ? readBodyReason(body)
                    : null) ?? TENANT_WIDE_ERROR_MESSAGE;

            return false;
        }

        return true;
    }

    return {
        shares,
        options,
        isTenantWide,
        loading,
        error,
        tenantWideError,
        load,
        loadOptions,
        create,
        revoke,
        setTenantWide,
    };
}
