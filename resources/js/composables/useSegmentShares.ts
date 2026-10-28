import { ref } from 'vue';
import type { Ref } from 'vue';
import SegmentShareOptionsController from '@/actions/App/Http/Controllers/Segments/SegmentShareOptionsController';
import SegmentSharesController from '@/actions/App/Http/Controllers/Segments/SegmentSharesController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';

export type GranteeType = 'team' | 'role' | 'user';

export interface GranteeOption {
    id: string;
    label: string;
}

export interface SegmentShare {
    id: string;
    grantee_type: GranteeType;
    grantee_id: string;
    can_edit: boolean;
}

interface RawSegmentShare {
    id: string;
    grantee_type: string;
    grantee_id: string;
    can_edit: boolean;
}

const GRANTEE_TYPE_BY_MORPH_CLASS: Record<string, GranteeType> = {
    'App\\Models\\Team': 'team',
    'App\\Models\\Role': 'role',
    'App\\Models\\User': 'user',
};

function isGranteeType(value: string): value is GranteeType {
    return value === 'team' || value === 'role' || value === 'user';
}

function granteeTypeFromMorphClass(raw: string): GranteeType {
    const mapped = GRANTEE_TYPE_BY_MORPH_CLASS[raw];

    if (mapped !== undefined) {
        return mapped;
    }

    if (isGranteeType(raw)) {
        return raw;
    }

    return raw as GranteeType;
}

function normalizeShare(raw: RawSegmentShare): SegmentShare {
    return {
        id: raw.id,
        grantee_type: granteeTypeFromMorphClass(raw.grantee_type),
        grantee_id: raw.grantee_id,
        can_edit: raw.can_edit,
    };
}

export type GranteeOptionMap = Record<GranteeType, GranteeOption[]>;

export function emptyGranteeOptions(): GranteeOptionMap {
    return { team: [], role: [], user: [] };
}

export interface CreateSharePayload {
    grantee_type: GranteeType;
    grantee_id: string;
    can_edit: boolean;
}

export interface UseSegmentSharesReturn {
    shares: Ref<SegmentShare[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    load: () => Promise<void>;
    create: (payload: CreateSharePayload) => Promise<SegmentShare | null>;
    revoke: (shareId: string) => Promise<boolean>;
}

const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE =
    'Die Freigaben konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const CREATE_ERROR_MESSAGE =
    'Die Freigabe konnte nicht angelegt werden. Bitte versuchen Sie es erneut.';

const REVOKE_ERROR_MESSAGE =
    'Die Freigabe konnte nicht widerrufen werden. Bitte versuchen Sie es erneut.';

interface RawGranteeOption {
    value: string;
    label: string;
}

function normalizeOptions(raw: unknown): GranteeOptionMap {
    const source = (raw ?? {}) as Partial<
        Record<GranteeType, RawGranteeOption[]>
    >;

    const map = emptyGranteeOptions();

    (Object.keys(map) as GranteeType[]).forEach((type) => {
        map[type] = (source[type] ?? []).map((option) => ({
            id: option.value,
            label: option.label,
        }));
    });

    return map;
}

export async function fetchGranteeOptions(
    segmentId: string,
): Promise<GranteeOptionMap> {
    const url = SegmentShareOptionsController.url({ segment: segmentId });

    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: buildHeaders(),
        method: 'GET',
    });

    if (!response.ok) {
        throw new Error(
            `Share options endpoint ${url} responded with status ${response.status}`,
        );
    }

    const payload = (await response.json()) as { options: unknown };

    return normalizeOptions(payload.options);
}

export function useSegmentShares(segmentId: string): UseSegmentSharesReturn {
    const shares = ref<SegmentShare[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);
    const endpoint = SegmentSharesController.index.url({ segment: segmentId });

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                headers: buildHeaders({
                    hasBody: init.body !== undefined && init.body !== null,
                }),
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
        }
    };

    const load = async (): Promise<void> => {
        loading.value = true;

        try {
            const response = await request(endpoint, { method: 'GET' });

            if (!response.ok) {
                throw new Error(
                    `Shares endpoint ${endpoint} responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                data: RawSegmentShare[];
            };

            shares.value = payload.data.map(normalizeShare);
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const create = async (
        payload: CreateSharePayload,
    ): Promise<SegmentShare | null> => {
        try {
            const response = await request(endpoint, {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    CREATE_ERROR_MESSAGE,
                );

                return null;
            }

            const body = (await response.json()) as { data: RawSegmentShare };

            const normalized = normalizeShare(body.data);

            shares.value = [...shares.value, normalized];
            error.value = null;

            return normalized;
        } catch {
            error.value = CREATE_ERROR_MESSAGE;

            return null;
        }
    };

    const revoke = async (shareId: string): Promise<boolean> => {
        const url = `${endpoint}/${shareId}`;

        try {
            const response = await request(url, { method: 'DELETE' });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    REVOKE_ERROR_MESSAGE,
                );

                return false;
            }

            shares.value = shares.value.filter((share) => share.id !== shareId);
            error.value = null;

            return true;
        } catch {
            error.value = REVOKE_ERROR_MESSAGE;

            return false;
        }
    };

    return {
        shares,
        loading,
        error,
        load,
        create,
        revoke,
    };
}
