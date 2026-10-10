import type { Ref } from 'vue';
import { getCurrentScope, onScopeDispose, ref } from 'vue';
import RecordRelationsController from '@/actions/App/Http/Controllers/Engine/RecordRelationsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readBodyReason } from '@/lib/errorResponse';
import type {
    RelationCandidate,
    RelationDirection,
    RelationEntry,
    RelationGroup,
} from '@/types/relations';

const REQUEST_TIMEOUT_MS = 15000;

const ENTRY_BLOCK_SIZE = 50;

const LOAD_ERROR_MESSAGE =
    'Die Beziehungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const WRITE_ERROR_MESSAGE =
    'Die Beziehung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export interface UseRecordRelationsReturn {
    groups: Ref<RelationGroup[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    load: (recordId: string) => Promise<void>;
    link: (
        recordId: string,
        relationshipTypeId: string,
        direction: RelationDirection,
        targetRecordId: string,
    ) => Promise<boolean>;
    unlink: (recordId: string, linkId: string) => Promise<boolean>;
    loadMoreOptions: (recordId: string, group: RelationGroup) => Promise<void>;
    searchOptions: (
        recordId: string,
        group: RelationGroup,
        term: string,
    ) => Promise<void>;
}

export function useRecordRelations(): UseRecordRelationsReturn {
    const groups = ref<RelationGroup[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const controllers = new Set<AbortController>();
    const pendingBlocks = new Set<string>();

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        controllers.add(controller);
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
            controllers.delete(controller);
        }
    };

    const load = async (recordId: string): Promise<void> => {
        loading.value = true;

        try {
            const response = await request(
                RecordRelationsController.index.url({ record: recordId }),
                { method: 'GET' },
            );

            if (!response.ok) {
                throw new Error(
                    `Relations endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                data?: { groups?: RelationGroup[] };
            };

            groups.value = payload.data?.groups ?? [];
            error.value = null;
        } catch {
            groups.value = [];
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const link = async (
        recordId: string,
        relationshipTypeId: string,
        direction: RelationDirection,
        targetRecordId: string,
    ): Promise<boolean> => {
        loading.value = true;

        try {
            const response = await request(
                RecordRelationsController.store.url({ record: recordId }),
                {
                    method: 'POST',
                    body: JSON.stringify({
                        relationship_type_id: relationshipTypeId,
                        direction,
                        target_record_id: targetRecordId,
                    }),
                },
            );

            const payload: unknown = await response.json().catch(() => null);

            if (!response.ok) {
                error.value = readBodyReason(payload) ?? WRITE_ERROR_MESSAGE;

                return false;
            }

            error.value = null;

            return true;
        } catch {
            error.value = WRITE_ERROR_MESSAGE;

            return false;
        } finally {
            loading.value = false;
        }
    };

    const unlink = async (
        recordId: string,
        linkId: string,
    ): Promise<boolean> => {
        loading.value = true;

        try {
            const response = await request(
                RecordRelationsController.destroy.url({
                    record: recordId,
                    link: linkId,
                }),
                { method: 'DELETE' },
            );

            const payload: unknown = await response.json().catch(() => null);

            if (!response.ok) {
                error.value = readBodyReason(payload) ?? WRITE_ERROR_MESSAGE;

                return false;
            }

            error.value = null;

            return true;
        } catch {
            error.value = WRITE_ERROR_MESSAGE;

            return false;
        } finally {
            loading.value = false;
        }
    };

    const blockBody = (group: RelationGroup, startRow: number): string =>
        JSON.stringify({
            relationshipTypeId: group.relationshipTypeId,
            direction: group.direction,
            startRow,
            endRow: startRow + ENTRY_BLOCK_SIZE,
            search: group.optionsSearch ?? null,
        });

    const fetchEntryBlock = async (
        recordId: string,
        group: RelationGroup,
        startRow: number,
    ): Promise<{ entries: RelationEntry[]; total: number } | null> => {
        const response = await request(
            RecordRelationsController.entries.url({ record: recordId }),
            { method: 'POST', body: blockBody(group, startRow) },
        );

        if (!response.ok) {
            return null;
        }

        const payload = (await response.json()) as {
            data?: { entries?: RelationEntry[]; total?: number };
        };

        return {
            entries: payload.data?.entries ?? [],
            total: payload.data?.total ?? 0,
        };
    };

    const fetchCandidateBlock = async (
        recordId: string,
        group: RelationGroup,
        startRow: number,
    ): Promise<{
        candidates: RelationCandidate[];
        hasMore: boolean;
    } | null> => {
        const response = await request(
            RecordRelationsController.candidates.url({ record: recordId }),
            { method: 'POST', body: blockBody(group, startRow) },
        );

        if (!response.ok) {
            return null;
        }

        const payload = (await response.json()) as {
            data?: { candidates?: RelationCandidate[]; hasMore?: boolean };
        };

        return {
            candidates: payload.data?.candidates ?? [],
            hasMore: payload.data?.hasMore ?? false,
        };
    };

    const knownEntryCount = (group: RelationGroup): number =>
        group.optionsSearch !== undefined && group.optionsSearch !== ''
            ? (group.entriesMatched ?? 0)
            : group.entriesTotal;

    const candidatesExhausted = (group: RelationGroup): boolean =>
        group.candidatesExhausted ?? !group.candidatesTruncated;

    const claimBlock = (
        group: RelationGroup,
        kind: string,
        startRow: number,
    ): string | null => {
        const blockKey = `${kind}|${group.relationshipTypeId}|${group.direction}|${group.optionsSearch ?? ''}|${startRow}`;

        if (pendingBlocks.has(blockKey)) {
            return null;
        }

        pendingBlocks.add(blockKey);

        return blockKey;
    };

    const loadMoreEntries = async (
        recordId: string,
        group: RelationGroup,
    ): Promise<void> => {
        const startRow = group.entries.length;
        const blockKey = claimBlock(group, 'entries', startRow);

        if (blockKey === null) {
            return;
        }

        try {
            const block = await fetchEntryBlock(recordId, group, startRow);

            if (block === null) {
                error.value = LOAD_ERROR_MESSAGE;

                return;
            }

            group.entries = [...group.entries, ...block.entries];
            group.entriesMatched = block.total;
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            pendingBlocks.delete(blockKey);
        }
    };

    const loadMoreCandidates = async (
        recordId: string,
        group: RelationGroup,
    ): Promise<void> => {
        const startRow = group.candidates.length;
        const blockKey = claimBlock(group, 'candidates', startRow);

        if (blockKey === null) {
            return;
        }

        try {
            const block = await fetchCandidateBlock(recordId, group, startRow);

            if (block === null) {
                error.value = LOAD_ERROR_MESSAGE;

                return;
            }

            group.candidates = [...group.candidates, ...block.candidates];
            group.candidatesExhausted = !block.hasMore;
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            pendingBlocks.delete(blockKey);
        }
    };

    const loadMoreOptions = async (
        recordId: string,
        group: RelationGroup,
    ): Promise<void> => {
        if (group.entries.length < knownEntryCount(group)) {
            await loadMoreEntries(recordId, group);

            return;
        }

        if (candidatesExhausted(group)) {
            return;
        }

        await loadMoreCandidates(recordId, group);
    };

    const searchOptions = async (
        recordId: string,
        group: RelationGroup,
        term: string,
    ): Promise<void> => {
        group.optionsSearch = term;

        try {
            const [entries, candidates] = await Promise.all([
                fetchEntryBlock(recordId, group, 0),
                fetchCandidateBlock(recordId, group, 0),
            ]);

            if (entries === null || candidates === null) {
                error.value = LOAD_ERROR_MESSAGE;

                return;
            }

            group.entries = entries.entries;
            group.entriesMatched = entries.total;
            group.candidates = candidates.candidates;
            group.candidatesExhausted = !candidates.hasMore;
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        }
    };

    if (getCurrentScope()) {
        onScopeDispose(() => {
            for (const controller of controllers) {
                controller.abort();
            }

            controllers.clear();
        });
    }

    return {
        groups,
        loading,
        error,
        load,
        link,
        unlink,
        loadMoreOptions,
        searchOptions,
    };
}
