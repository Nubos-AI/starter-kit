import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CreatableObjectType } from '@/composables/useQuickCreate';
import { useQuickCreate } from '@/composables/useQuickCreate';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const toastError = vi.fn();
const recordToast = vi.fn();
const routerVisit = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@/lib/recordToast', () => ({
    recordToast: (...args: unknown[]) => recordToast(...args),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (...args: unknown[]) => routerVisit(...args) },
}));

const objectType = {
    id: 'ot-1',
    key: 'company',
    slug: 'companies',
    name: 'Firmen',
    requiresDeletionReason: false,
    hasHierarchy: false,
    fieldDefinitions: [],
} as CreatableObjectType;

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

beforeEach(() => {
    toastError.mockClear();
    recordToast.mockClear();
    routerVisit.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useQuickCreate', () => {
    it('posts the entered values to the create endpoint of the chosen type', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(201, { data: { id: 'rec-1', data: {} } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const quickCreate = useQuickCreate();
        quickCreate.select(objectType);
        quickCreate.values.value = { title: 'Neu' };

        await quickCreate.submit();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/nubos/engine/records/companies');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({
            data: { title: 'Neu' },
        });
        expect(recordToast).toHaveBeenCalledWith('create');
        expect(routerVisit).toHaveBeenCalled();
    });

    it('maps the field errors of a 422 rejection onto the plain field keys', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Die Eingaben sind unvollständig.',
                        errors: {
                            'data.title': ['Der Titel ist erforderlich.'],
                            owner_id: ['Unbekannter Besitzer.'],
                        },
                    }),
                ),
            ),
        );

        const quickCreate = useQuickCreate();
        quickCreate.select(objectType);

        await quickCreate.submit();

        expect(quickCreate.errors.value).toEqual({
            title: 'Der Titel ist erforderlich.',
            owner_id: 'Unbekannter Besitzer.',
        });
        expect(routerVisit).not.toHaveBeenCalled();
        expect(quickCreate.saving.value).toBe(false);
    });

    it('shows the reason the server rejected the record with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Die Eingaben sind unvollständig.',
                        errors: {
                            'data.title': ['Der Titel ist erforderlich.'],
                        },
                    }),
                ),
            ),
        );

        const quickCreate = useQuickCreate();
        quickCreate.select(objectType);

        await quickCreate.submit();

        expect(toastError).toHaveBeenCalledWith(
            'Die Eingaben sind unvollständig.',
        );
    });

    it('shows the reason of a refusal that carries no field errors', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(403, {
                        message: 'Sie dürfen hier nichts anlegen.',
                    }),
                ),
            ),
        );

        const quickCreate = useQuickCreate();
        quickCreate.select(objectType);

        await quickCreate.submit();

        expect(toastError).toHaveBeenCalledWith(
            'Sie dürfen hier nichts anlegen.',
        );
    });

    it('sends the catalog request and keeps the returned types', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(200, [objectType]))),
        );

        const quickCreate = useQuickCreate();

        await quickCreate.loadCatalog();

        expect(quickCreate.catalog.value).toEqual([objectType]);
    });
});
