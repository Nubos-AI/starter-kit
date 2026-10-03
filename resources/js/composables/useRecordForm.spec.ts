import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useRecordForm } from '@/composables/useRecordForm';
import type { RecordObjectType, RecordPayload } from '@/types/records';

const toastError = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@/lib/recordToast', () => ({ recordToast: vi.fn() }));

const objectType: RecordObjectType = {
    id: 'ot-1',
    key: 'deals',
    slug: 'deals',
    name: 'Deals',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const record = {
    id: '01RECORD0K5N3Q8V9WYE6M2H7X',
    objectTypeId: 'ot-1',
    stageId: null,
    ownerId: 'u-1',
    recordNumber: 'REC-1',
    title: 'Angebot A',
    externalReferenceId: null,
    version: 3,
    data: { titel: 'Angebot A' },
} as unknown as RecordPayload;

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

function buildForm(): ReturnType<typeof useRecordForm> {
    return useRecordForm({
        mode: 'edit',
        objectType,
        record,
        collaboratorIds: ['u-2'],
        onSaved: vi.fn(),
    });
}

function lastBody(
    fetchMock: ReturnType<typeof vi.fn>,
): Record<string, unknown> {
    const [, init] = fetchMock.mock.calls.at(-1) as [string, RequestInit];

    return JSON.parse(String(init.body));
}

beforeEach(() => {
    toastError.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useRecordForm — the assignment saves on its own', () => {
    it('sends owner and collaborators with the current version and no field data', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: { ...record, version: 4, ownerId: 'u-9' },
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.ownerId.value = 'u-9';
        await form.saveAssignment();

        expect(lastBody(fetchMock)).toEqual({
            version: 3,
            owner_id: 'u-9',
            collaborator_ids: ['u-2'],
        });
    });

    it('carries the version the server answered into the next save', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { ...record, version: 4 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.ownerId.value = 'u-9';
        await form.saveAssignment();

        form.collaboratorIds.value = ['u-3'];
        await form.saveAssignment();

        expect(lastBody(fetchMock).version).toBe(4);
    });

    it('puts the owner back and warns when the server refuses', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(403, { message: 'nope' })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.ownerId.value = 'u-9';
        await form.saveAssignment();

        expect(form.ownerId.value).toBe('u-1');
        expect(toastError).toHaveBeenCalled();
    });

    it('puts the collaborators back when the server refuses', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(422, { errors: {} })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.collaboratorIds.value = ['u-2', 'u-3'];
        await form.saveAssignment();

        expect(form.collaboratorIds.value).toEqual(['u-2']);
    });

    it('opens the conflict dialog and restores the assignment on a stale version', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(409, { data: { ...record, version: 7 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.ownerId.value = 'u-9';
        await form.saveAssignment();

        expect(form.conflictOpen.value).toBe(true);
        expect(form.ownerId.value).toBe('u-1');
    });

    it('keeps unsaved field edits untouched while the assignment saves', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { ...record, version: 4 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const form = buildForm();
        form.values.value = { titel: 'Angebot B' };
        form.ownerId.value = 'u-9';
        await form.saveAssignment();

        expect(form.values.value).toEqual({ titel: 'Angebot B' });
    });
});

describe('useRecordForm — a rejected save is visible', () => {
    function buildCreateForm(
        onSaved = vi.fn(),
    ): ReturnType<typeof useRecordForm> {
        return useRecordForm({
            mode: 'create',
            objectType,
            record: null,
            onSaved,
        });
    }

    it('binds a rejected field to the key the form renders', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'ux6_kategorie contains an invalid value.',
                        errors: {
                            'data.ux6_kategorie': [
                                'ux6_kategorie contains an invalid value.',
                            ],
                        },
                    }),
                ),
            ),
        );

        const form = buildForm();
        await form.submit();

        expect(form.errors.value).toEqual({
            ux6_kategorie: 'ux6_kategorie contains an invalid value.',
        });
    });

    it('reports the server reason as a toast when editing', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'ux6_kategorie contains an invalid value.',
                        errors: {
                            'data.ux6_kategorie': [
                                'ux6_kategorie contains an invalid value.',
                            ],
                        },
                    }),
                ),
            ),
        );

        const form = buildForm();
        await form.submit();

        expect(toastError).toHaveBeenCalledWith(
            'ux6_kategorie contains an invalid value.',
        );
    });

    it('reports the server reason as a toast when creating', async () => {
        const onSaved = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'customer_no has already been taken.',
                        errors: {
                            'data.customer_no': [
                                'customer_no has already been taken.',
                            ],
                        },
                    }),
                ),
            ),
        );

        const form = buildCreateForm(onSaved);
        await form.submit();

        expect(toastError).toHaveBeenCalledWith(
            'customer_no has already been taken.',
        );
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('stays visible when the server names no field at all', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(422, {}))),
        );

        const form = buildForm();
        await form.submit();

        expect(form.errors.value).toEqual({});
        expect(toastError).toHaveBeenCalledWith(
            'Der Datensatz konnte nicht gespeichert werden.',
        );
    });

    it('keeps a field error that carries no data prefix', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'The version field is required.',
                        errors: { version: ['The version field is required.'] },
                    }),
                ),
            ),
        );

        const form = buildForm();
        await form.submit();

        expect(form.errors.value).toEqual({
            version: 'The version field is required.',
        });
    });
});
