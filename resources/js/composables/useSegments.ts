import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import SegmentsController from '@/actions/App/Http/Controllers/Segments/SegmentsController';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type { RecordObjectType } from '@/types/records';

export interface SegmentSummary {
    id: string;
    name: string;
    object_type_id: string | null;
    is_system: boolean;
    is_default: boolean;
    is_owner: boolean;
}

export interface SegmentGroups {
    default: SegmentSummary[];
    system: SegmentSummary[];
    own: SegmentSummary[];
    shared: SegmentSummary[];
}

export interface StoreSegmentPayload {
    name: string;
    object_type_id: string | null;
    filter_definition: FilterGroupNode | null;
}

export interface SegmentSelection {
    segment: SegmentSummary;
}

export interface UseSegmentsReturn {
    segments: Ref<SegmentSummary[]>;
    groups: ComputedRef<SegmentGroups>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    load: () => Promise<void>;
    save: (payload: StoreSegmentPayload) => Promise<SegmentSummary | null>;
}

const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE =
    'Die Segmente konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const SAVE_ERROR_MESSAGE =
    'Das Segment konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

function normalizeSegment(raw: unknown): SegmentSummary {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        name: String(source.name ?? ''),
        object_type_id:
            source.object_type_id === null ||
            source.object_type_id === undefined
                ? null
                : String(source.object_type_id),
        is_system: Boolean(source.is_system),
        is_default: Boolean(source.is_default),
        is_owner: Boolean(source.is_owner),
    };
}

export function useSegments(objectType: RecordObjectType): UseSegmentsReturn {
    const segments = ref<SegmentSummary[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const groups = computed<SegmentGroups>(() => {
        const buckets: SegmentGroups = {
            default: [],
            system: [],
            own: [],
            shared: [],
        };

        for (const segment of segments.value) {
            if (segment.is_default) {
                buckets.default.push(segment);
            } else if (segment.is_system) {
                buckets.system.push(segment);
            } else if (segment.is_owner) {
                buckets.own.push(segment);
            } else {
                buckets.shared.push(segment);
            }
        }

        return buckets;
    });

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

        const url = SegmentsController.index.url(undefined, {
            query: { object_type: objectType.slug },
        });

        try {
            const response = await request(url, { method: 'GET' });

            if (!response.ok) {
                throw new Error(
                    `Segments endpoint ${url} responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                data: unknown[];
            };

            segments.value = (payload.data ?? []).map(normalizeSegment);
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const save = async (
        payload: StoreSegmentPayload,
    ): Promise<SegmentSummary | null> => {
        try {
            const response = await request(SegmentsController.store.url(), {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    SAVE_ERROR_MESSAGE,
                );

                return null;
            }

            const body = (await response.json()) as { data: unknown };
            const saved = normalizeSegment(body.data);

            if (saved.id === '') {
                error.value = SAVE_ERROR_MESSAGE;

                return null;
            }

            error.value = null;

            return saved;
        } catch {
            error.value = SAVE_ERROR_MESSAGE;

            return null;
        }
    };

    return {
        segments,
        groups,
        loading,
        error,
        load,
        save,
    };
}
