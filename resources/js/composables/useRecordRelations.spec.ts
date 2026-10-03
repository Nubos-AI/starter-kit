import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRecordRelations } from '@/composables/useRecordRelations';
import type { RelationGroup } from '@/types/relations';
import { setUrlDefaults } from '@/wayfinder';

const RECORD_ID = '01RECORD00000000000000000A';

const RELATIONSHIP_TYPE_ID = '01RELTYPE000000000000000B';

const TARGET_RECORD_ID = '01RECORD00000000000000000C';

const LINK_ID = '01LINK00000000000000000000';

const WRITE_FALLBACK =
    'Die Beziehung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

setUrlDefaults({ activeTeam: 'nubos' });

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function stubFetch(response: Response): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(response)),
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useRecordRelations — linking a record', () => {
    it('shows the field error the server named, not only the summary', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Die Daten sind ungültig.',
                errors: {
                    target_record_id: [
                        'Der Zieldatensatz gehört zu einem anderen Objekttyp.',
                    ],
                },
            }),
        );

        const relations = useRecordRelations();

        await expect(
            relations.link(
                RECORD_ID,
                RELATIONSHIP_TYPE_ID,
                'outgoing',
                TARGET_RECORD_ID,
            ),
        ).resolves.toBe(false);

        expect(relations.error.value).toBe(
            'Der Zieldatensatz gehört zu einem anderen Objekttyp.',
        );
    });

    it('shows the message the server sent when no field error names a cause', async () => {
        stubFetch(
            jsonResponse(403, {
                message: 'Sie dürfen diese Beziehung nicht anlegen.',
            }),
        );

        const relations = useRecordRelations();

        await expect(
            relations.link(
                RECORD_ID,
                RELATIONSHIP_TYPE_ID,
                'outgoing',
                TARGET_RECORD_ID,
            ),
        ).resolves.toBe(false);

        expect(relations.error.value).toBe(
            'Sie dürfen diese Beziehung nicht anlegen.',
        );
    });

    it('keeps the German fallback when the refusal carries no reason', async () => {
        stubFetch(jsonResponse(500, {}));

        const relations = useRecordRelations();

        await expect(
            relations.link(
                RECORD_ID,
                RELATIONSHIP_TYPE_ID,
                'outgoing',
                TARGET_RECORD_ID,
            ),
        ).resolves.toBe(false);

        expect(relations.error.value).toBe(WRITE_FALLBACK);
    });
});

describe('useRecordRelations — unlinking a record', () => {
    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Die Beziehung ist pflichtig.',
                errors: { link: ['Die Beziehung ist pflichtig.'] },
            }),
        );

        const relations = useRecordRelations();

        await expect(relations.unlink(RECORD_ID, LINK_ID)).resolves.toBe(false);

        expect(relations.error.value).toBe('Die Beziehung ist pflichtig.');
    });

    it('keeps the German fallback when the request never reaches the server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const relations = useRecordRelations();

        await expect(relations.unlink(RECORD_ID, LINK_ID)).resolves.toBe(false);

        expect(relations.error.value).toBe(WRITE_FALLBACK);
    });
});

describe('useRecordRelations — loading further options', () => {
    function cappedGroup(loaded: number, total: number): RelationGroup {
        return {
            relationshipTypeId: RELATIONSHIP_TYPE_ID,
            direction: 'outgoing',
            isHierarchy: false,
            objectTypeName: 'Projekte',
            roleLabel: 'Projekte',
            acceptsOne: false,
            canEdit: false,
            candidates: [],
            candidatesTruncated: true,
            entries: Array.from({ length: loaded }, (_, index) => ({
                linkId: `link-${index}`,
                recordId: `record-${index}`,
                recordNumber: `PR-${index}`,
                label: `Projekt ${index}`,
            })),
            entriesTotal: total,
            entriesTruncated: true,
        };
    }

    it('asks for the block that starts where the loaded entries end', async () => {
        const fetchMock = vi.fn(
            (): Promise<Response> =>
                Promise.resolve(
                    jsonResponse(200, { data: { entries: [], lastRow: 50 } }),
                ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [cappedGroup(50, 1200)];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        const [, init] = (fetchMock.mock.calls[0] ?? []) as unknown as [
            string,
            RequestInit,
        ];

        const body = JSON.parse(String(init.body)) as Record<string, unknown>;

        expect(body.startRow).toBe(50);
        expect(body.relationshipTypeId).toBe(RELATIONSHIP_TYPE_ID);
        expect(body.direction).toBe('outgoing');
    });

    it('appends the block instead of replacing what is already there', async () => {
        stubFetch(
            jsonResponse(200, {
                data: {
                    entries: [
                        {
                            linkId: 'link-50',
                            recordId: 'record-50',
                            recordNumber: 'PR-50',
                            label: 'Projekt 50',
                        },
                    ],
                    lastRow: null,
                },
            }),
        );

        const relations = useRecordRelations();
        relations.groups.value = [cappedGroup(50, 1200)];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        expect(relations.groups.value[0].entries).toHaveLength(51);
        expect(relations.groups.value[0].entries[50].recordId).toBe(
            'record-50',
        );
    });

    it('never asks twice for the same block while the first ask is still open', async () => {
        let release: (value: Response) => void = () => {};
        const pending = new Promise<Response>((resolve) => {
            release = resolve;
        });
        const fetchMock = vi.fn(() => pending);
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [cappedGroup(50, 1200)];

        const first = relations.loadMoreOptions(
            RECORD_ID,
            relations.groups.value[0],
        );
        const second = relations.loadMoreOptions(
            RECORD_ID,
            relations.groups.value[0],
        );

        release(jsonResponse(200, { data: { entries: [], lastRow: 50 } }));
        await Promise.all([first, second]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('stops asking once every entry and every candidate is loaded', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { entries: [], lastRow: 2 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [
            { ...cappedGroup(2, 2), candidatesTruncated: false },
        ];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('turns to the candidates once every entry is loaded', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: {
                        candidates: [{ id: 'record-99', label: 'Projekt 99' }],
                        hasMore: false,
                    },
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [cappedGroup(2, 2)];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        const [url, init] = (fetchMock.mock.calls[0] ?? []) as unknown as [
            string,
            RequestInit,
        ];

        const body = JSON.parse(String(init.body)) as Record<string, unknown>;

        expect(url).toContain('/relations/candidates');
        expect(body.startRow).toBe(0);
        expect(relations.groups.value[0].candidates).toHaveLength(1);
        expect(relations.groups.value[0].candidatesExhausted).toBe(true);
    });

    it('asks for the candidate block that starts where the loaded candidates end', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { candidates: [], hasMore: true } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [
            {
                ...cappedGroup(2, 2),
                candidates: [
                    { id: 'candidate-0', label: 'Kandidat 0' },
                    { id: 'candidate-1', label: 'Kandidat 1' },
                ],
            },
        ];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        const [, init] = (fetchMock.mock.calls[0] ?? []) as unknown as [
            string,
            RequestInit,
        ];

        expect(
            (JSON.parse(String(init.body)) as Record<string, unknown>).startRow,
        ).toBe(2);
    });
});

describe('useRecordRelations — searching the options', () => {
    function searchGroup(): RelationGroup {
        return {
            relationshipTypeId: RELATIONSHIP_TYPE_ID,
            direction: 'outgoing',
            isHierarchy: false,
            objectTypeName: 'Projekte',
            roleLabel: 'Projekte',
            acceptsOne: false,
            canEdit: true,
            candidates: [{ id: 'candidate-0', label: 'Kandidat 0' }],
            candidatesTruncated: true,
            entries: [
                {
                    linkId: 'link-0',
                    recordId: 'record-0',
                    recordNumber: 'PR-0',
                    label: 'Projekt 0',
                },
            ],
            entriesTotal: 1200,
            entriesTruncated: true,
        };
    }

    it('asks the server for entries and candidates that match the term', async () => {
        const fetchMock = vi.fn((url: string) =>
            Promise.resolve(
                jsonResponse(
                    200,
                    url.includes('/relations/candidates')
                        ? {
                              data: {
                                  candidates: [
                                      { id: 'record-40', label: 'Projekt 40' },
                                  ],
                                  hasMore: false,
                              },
                          }
                        : {
                              data: {
                                  entries: [
                                      {
                                          linkId: 'link-40',
                                          recordId: 'record-40b',
                                          recordNumber: 'PR-40',
                                          label: 'Projekt 40b',
                                      },
                                  ],
                                  total: 1,
                              },
                          },
                ),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [searchGroup()];

        await relations.searchOptions(
            RECORD_ID,
            relations.groups.value[0],
            '40',
        );

        const group = relations.groups.value[0];

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(group.optionsSearch).toBe('40');
        expect(group.entries).toHaveLength(1);
        expect(group.entriesMatched).toBe(1);
        expect(group.candidates).toEqual([
            { id: 'record-40', label: 'Projekt 40' },
        ]);
        expect(group.candidatesExhausted).toBe(true);
    });

    it('carries the term into every further block it asks for', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { candidates: [], hasMore: true } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const relations = useRecordRelations();
        relations.groups.value = [
            { ...searchGroup(), optionsSearch: '40', entriesMatched: 1 },
        ];

        await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

        const [, init] = (fetchMock.mock.calls[0] ?? []) as unknown as [
            string,
            RequestInit,
        ];

        expect(
            (JSON.parse(String(init.body)) as Record<string, unknown>).search,
        ).toBe('40');
    });
});
