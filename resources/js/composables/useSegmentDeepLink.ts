import { router } from '@inertiajs/vue3';
import { useUrlSearchParams } from '@vueuse/core';
import { ref } from 'vue';
import type { Ref } from 'vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { DRILL_DOWN_PARAM_KEYS } from '@/composables/useReportDrillDown';
import type { DrillDownParamKey } from '@/composables/useReportDrillDown';
import { useUserPreferences } from '@/composables/useUserPreferences';
import { readUrlParam } from '@/lib/urlParams';
import type { RecordObjectType } from '@/types/records';

export interface UseSegmentDeepLinkReturn {
    activeSegmentId: Ref<string | null>;
    activeFilter: Ref<FilterGroupNode | null>;
    syncFromUrl: () => void;
    applySegment: (id: string) => void;
    applyFilter: (tree: FilterGroupNode) => void;
    clear: () => void;
    restoreLastUsed: () => Promise<void>;
}

export interface LastUsedPreference {
    lastSegmentId: string | null;
    lastFilter: FilterGroupNode | null;
}

type DeepLinkParams = {
    segment?: string | null;
    filter?: string | null;
} & { [Key in DrillDownParamKey]?: string | null };

const SERVER_REF_PREFIX = 'fs_';

function encodeFilterTree(tree: FilterGroupNode): string {
    const bytes = new TextEncoder().encode(JSON.stringify(tree));
    const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join(
        '',
    );

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

function decodeFilterTree(encoded: string): FilterGroupNode {
    const base64 = encoded.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(
        base64.length + ((4 - (base64.length % 4)) % 4),
        '=',
    );
    const bytes = Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));

    return JSON.parse(new TextDecoder().decode(bytes)) as FilterGroupNode;
}

export function useSegmentDeepLink(
    objectType: RecordObjectType,
    lastUsed: LastUsedPreference = { lastSegmentId: null, lastFilter: null },
): UseSegmentDeepLinkReturn {
    const params = useUrlSearchParams<DeepLinkParams>('history', {
        writeMode: 'replace',
        removeNullishValues: true,
    });
    const activeSegmentId = ref<string | null>(null);
    const activeFilter = ref<FilterGroupNode | null>(null);
    const { patch } = useUserPreferences();

    function remember(
        lastSegmentId: string | null,
        lastFilter: FilterGroupNode | null,
    ): void {
        patch({
            objectTypes: {
                [objectType.id]: { lastSegmentId, lastFilter },
            },
        });
    }

    function dropDrillDown(): void {
        for (const key of DRILL_DOWN_PARAM_KEYS) {
            params[key] = null;
        }
    }

    function applySegment(id: string): void {
        activeSegmentId.value = id;
        activeFilter.value = null;
        dropDrillDown();
        params.filter = null;
        params.segment = id;
        remember(id, null);
    }

    function applyFilter(tree: FilterGroupNode): void {
        activeFilter.value = tree;
        activeSegmentId.value = null;
        params.segment = null;
        params.filter = encodeFilterTree(tree);
        remember(null, tree);
    }

    function clear(): void {
        activeSegmentId.value = null;
        activeFilter.value = null;
        dropDrillDown();
        params.segment = null;
        params.filter = null;
        remember(null, null);
    }

    function syncFromUrl(): void {
        const segment = readUrlParam(params, 'segment');

        if (segment !== null) {
            activeSegmentId.value = segment;
            activeFilter.value = null;

            return;
        }

        const filter = readUrlParam(params, 'filter');

        if (filter === null) {
            activeSegmentId.value = null;
            activeFilter.value = null;

            return;
        }

        activeSegmentId.value = null;
        activeFilter.value = null;

        if (filter.startsWith(SERVER_REF_PREFIX)) {
            router.reload({ data: { filter } });

            return;
        }

        try {
            activeFilter.value = decodeFilterTree(filter);
        } catch {
            activeFilter.value = null;
        }
    }

    async function restoreLastUsed(): Promise<void> {
        if (
            readUrlParam(params, 'segment') !== null ||
            readUrlParam(params, 'filter') !== null ||
            readUrlParam(params, 'report') !== null
        ) {
            return;
        }

        if (lastUsed.lastSegmentId !== null) {
            activeSegmentId.value = lastUsed.lastSegmentId;
            activeFilter.value = null;

            return;
        }

        activeSegmentId.value = null;
        activeFilter.value = lastUsed.lastFilter;
    }

    return {
        activeSegmentId,
        activeFilter,
        syncFromUrl,
        applySegment,
        applyFilter,
        clear,
        restoreLastUsed,
    };
}
