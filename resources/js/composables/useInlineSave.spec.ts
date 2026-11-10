import type { CellValueChangedEvent } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useInlineSave } from '@/composables/useInlineSave';
import type { RecordPayload } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const toastSuccess = vi.fn();
const toastError = vi.fn();
const recordToastMock = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: (...args: unknown[]) => toastError(...args),
    },
}));

vi.mock('@/lib/recordToast', () => ({
    recordToast: (...args: unknown[]) => recordToastMock(...args),
}));

interface FakeNode {
    data: RecordPayload;
    setData: (value: RecordPayload) => void;
}

function makeEvent(): {
    event: CellValueChangedEvent<RecordPayload>;
    node: FakeNode;
} {
    const node: FakeNode = {
        data: {
            id: 'r1',
            version: 3,
            data: { title: 'old' },
        } as unknown as RecordPayload,
        setData(value: RecordPayload) {
            this.data = value;
        },
    };
    vi.spyOn(node, 'setData');

    const event = {
        node,
        column: { getColId: () => 'title' },
        newValue: 'new',
        oldValue: 'old',
    } as unknown as CellValueChangedEvent<RecordPayload>;

    return { event, node };
}

function fakeResponse(status: number, body: unknown): Response {
    return {
        status,
        json: async () => body,
    } as unknown as Response;
}

beforeEach(() => {
    toastSuccess.mockClear();
    toastError.mockClear();
    recordToastMock.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useInlineSave — auto-save on cell change (SC-7)', () => {
    it('PATCHes the cell endpoint with field, value and optimistic version', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            fakeResponse(200, {
                id: 'r1',
                version: 4,
                data: { title: 'new' },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { onCellValueChanged } = useInlineSave();
        const { event } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/nubos/engine/records/r1/cell');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toEqual({
            field: 'title',
            value: 'new',
            version: 3,
        });
    });

    it('commits the server row and shows an edit record toast on 200', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                fakeResponse(200, {
                    id: 'r1',
                    version: 4,
                    data: { title: 'new' },
                }),
            ),
        );

        const { onCellValueChanged, conflictOpen } = useInlineSave();
        const { event, node } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() =>
            expect(recordToastMock).toHaveBeenCalledWith('edit'),
        );
        expect(node.setData).toHaveBeenCalledWith(
            expect.objectContaining({ version: 4 }),
        );
        expect(conflictOpen.value).toBe(false);
        expect(toastError).not.toHaveBeenCalled();
    });

    it('opens the conflict dialog and refreshes the row on a 409 version conflict', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                fakeResponse(409, {
                    id: 'r1',
                    version: 9,
                    data: { title: 'server-wins' },
                }),
            ),
        );

        const { onCellValueChanged, conflictOpen, closeConflict } =
            useInlineSave();
        const { event, node } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() => expect(conflictOpen.value).toBe(true));
        expect(node.setData).toHaveBeenCalledWith(
            expect.objectContaining({ version: 9 }),
        );
        expect(toastSuccess).not.toHaveBeenCalled();

        closeConflict();
        expect(conflictOpen.value).toBe(false);
    });

    it('shows the field reason the server sent with a 422 rejection', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                fakeResponse(422, {
                    message: 'The given data was invalid.',
                    errors: { 'data.title': ['Der Titel ist zu lang.'] },
                }),
            ),
        );

        const { onCellValueChanged, announcement } = useInlineSave();
        const { event, node } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
        expect(toastError).toHaveBeenCalledWith('Der Titel ist zu lang.');
        await vi.waitFor(() =>
            expect(announcement.value).toBe('Der Titel ist zu lang.'),
        );
        expect(node.data.data.title).toBe('old');
    });

    it('falls back to the top level message when no field error matches', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                fakeResponse(403, {
                    message: 'Sie dürfen dieses Feld nicht bearbeiten.',
                }),
            ),
        );

        const { onCellValueChanged } = useInlineSave();
        const { event } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
        expect(toastError).toHaveBeenCalledWith(
            'Sie dürfen dieses Feld nicht bearbeiten.',
        );
    });

    it('reverts the cell and shows an error toast on a non-2xx failure', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(fakeResponse(500, {})),
        );

        const { onCellValueChanged } = useInlineSave();
        const { event, node } = makeEvent();
        onCellValueChanged(event);

        await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
        expect(node.data.data.title).toBe('old');
        expect(node.setData).toHaveBeenCalled();
    });
});
