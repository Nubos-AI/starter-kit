import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    destroy as destroyAction,
    index as indexAction,
    preview as previewAction,
    store as storeAction,
    update as updateAction,
} from '@/actions/App/Http/Controllers/Notifications/NotificationRulesController';
import {
    addLeadStage,
    availableWatchFields,
    isFieldEncrypted,
    removeLeadStage,
    useRules,
} from '@/composables/useRules';
import type { RuleInput } from '@/composables/useRules';
import type { FieldDefinition } from '@/types/fields';

const OBJECT_TYPE_ID = '01OBJTYPE0K5N3Q8V9WYE6M2H7';

const OBJECT_TYPE_SLUG = 'companies';
const RULE_ID = '01RULE000K5N3Q8V9WYE6M2H7C';
const SEGMENT_ID = '01SEG0000K5N3Q8V9WYE6M2H7C';

vi.mock(
    '@/actions/App/Http/Controllers/Notifications/NotificationRulesController',
    () => ({
        index: { url: () => '/api/notification-rules', method: 'get' },
        store: { url: () => '/api/notification-rules', method: 'post' },
        update: {
            url: () => '/api/notification-rules/update',
            method: 'put',
        },
        destroy: {
            url: () => '/api/notification-rules/destroy',
            method: 'delete',
        },
        preview: {
            url: () => '/api/notification-rules/preview',
            method: 'post',
        },
    }),
);

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        message: vi.fn(),
    },
    Toaster: { name: 'ToasterStub', render: () => null },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({ props: { auth: { user: { id: 'u1' } } } }),
}));

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function makeField(
    key: string,
    overrides: Record<string, unknown> = {},
): FieldDefinition {
    return {
        key,
        field_type: 'text_short',
        label: key,
        is_required: false,
        ...overrides,
    } as FieldDefinition;
}

function createInput(): RuleInput {
    return {
        object_type_id: OBJECT_TYPE_ID,
        name: 'Due quotes',
        trigger_type: 'date_based',
        config: {
            date_field_key: 'close_date',
            lead_stages: [{ value: 3, unit: 'day' }],
        },
        segment_id: null,
        filter_definition: null,
        action: { notify: true, create_reminder: null },
        is_active: true,
    };
}

function bodyOf(
    fetchMock: ReturnType<typeof vi.fn>,
    url: string,
): Record<string, unknown> {
    const call = fetchMock.mock.calls.find((entry) => entry[0] === url);
    expect(call).toBeTruthy();
    const raw = (call?.[1] as RequestInit | undefined)?.body;
    expect(typeof raw).toBe('string');

    return JSON.parse(raw as string) as Record<string, unknown>;
}

beforeEach(() => {
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=test-xsrf-token',
    } as unknown as Document);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useRules composable — CRUD over Wayfinder actions', () => {
    it('load(objectTypeSlug) GETs the index endpoint and populates rules from {data}', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: [
                        { id: 'rule-1', name: 'A' },
                        { id: 'rule-2', name: 'B' },
                    ],
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { rules, error, load } = useRules();

        await load(OBJECT_TYPE_SLUG);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            indexAction.url({ objectType: OBJECT_TYPE_SLUG }),
            expect.objectContaining({ method: 'GET' }),
        );
        expect(rules.value.map((rule) => rule.id)).toEqual([
            'rule-1',
            'rule-2',
        ]);
        expect(error.value).toBeNull();
    });

    it('load(objectTypeSlug) yields an empty list and no error for {data: []}', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: [] })));
        vi.stubGlobal('fetch', fetchMock);

        const { rules, error, load } = useRules();

        await load(OBJECT_TYPE_SLUG);
        await flushPromises();

        expect(rules.value).toEqual([]);
        expect(error.value).toBeNull();
    });

    it('create(input) POSTs the store endpoint carrying the full rule payload', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(201, { data: { id: RULE_ID } })));
        vi.stubGlobal('fetch', fetchMock);

        const { create } = useRules();

        const input = createInput();
        const result = await create(input);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            storeAction.url(),
            expect.objectContaining({ method: 'POST' }),
        );

        const body = bodyOf(fetchMock, storeAction.url());
        expect(body).toEqual(
            expect.objectContaining({
                object_type_id: OBJECT_TYPE_ID,
                name: 'Due quotes',
                trigger_type: 'date_based',
                config: {
                    date_field_key: 'close_date',
                    lead_stages: [{ value: 3, unit: 'day' }],
                },
                segment_id: null,
                filter_definition: null,
                action: { notify: true, create_reminder: null },
                is_active: true,
            }),
        );
        expect(result).not.toBeNull();
    });

    it('update(id, input) PUTs the update endpoint with the changed fields', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: { id: RULE_ID } })));
        vi.stubGlobal('fetch', fetchMock);

        const { update } = useRules();

        await update(RULE_ID, { ...createInput(), name: 'Umbenannt' });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            updateAction.url({ rule: RULE_ID }),
            expect.objectContaining({ method: 'PUT' }),
        );

        const body = bodyOf(fetchMock, updateAction.url({ rule: RULE_ID }));
        expect(body).toEqual(expect.objectContaining({ name: 'Umbenannt' }));
    });

    it('destroy(id) DELETEs the destroy endpoint and returns true', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { destroy } = useRules();

        const result = await destroy(RULE_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            destroyAction.url({ rule: RULE_ID }),
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(result).toBe(true);
    });

    it('toggleActive(id, isActive) PUTs the update endpoint carrying is_active', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: { id: RULE_ID } })));
        vi.stubGlobal('fetch', fetchMock);

        const { toggleActive } = useRules();

        await toggleActive(RULE_ID, false);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            updateAction.url({ rule: RULE_ID }),
            expect.objectContaining({ method: 'PUT' }),
        );

        const body = bodyOf(fetchMock, updateAction.url({ rule: RULE_ID }));
        expect(body).toEqual(expect.objectContaining({ is_active: false }));
    });
});

describe('useRules composable — preview on scope commit', () => {
    it('preview(scope) issues exactly one request and lands {count, approximate}', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, { count: 12, approximate: false }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { preview, previewCount, previewApproximate, previewLoading } =
            useRules();

        await preview({
            object_type_id: OBJECT_TYPE_ID,
            segment_id: SEGMENT_ID,
            filter_definition: null,
        });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(
            previewAction.url(),
            expect.objectContaining({ method: 'POST' }),
        );

        const body = bodyOf(fetchMock, previewAction.url());
        expect(body).toEqual(
            expect.objectContaining({
                object_type_id: OBJECT_TYPE_ID,
                segment_id: SEGMENT_ID,
                filter_definition: null,
            }),
        );

        expect(previewCount.value).toBe(12);
        expect(previewApproximate.value).toBe(false);
        expect(previewLoading.value).toBe(false);
    });

    it('preview(scope) renders the exact count for a {count: N} payload', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, { count: 47, approximate: false }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { preview, previewCount } = useRules();

        await preview({
            object_type_id: OBJECT_TYPE_ID,
            segment_id: null,
            filter_definition: null,
        });
        await flushPromises();

        expect(previewCount.value).toBe(47);
    });

    it('preview(scope) marks approximate true for a large capped count', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, { count: 5000, approximate: true }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { preview, previewCount, previewApproximate } = useRules();

        await preview({
            object_type_id: OBJECT_TYPE_ID,
            segment_id: null,
            filter_definition: null,
        });
        await flushPromises();

        expect(previewCount.value).toBe(5000);
        expect(previewApproximate.value).toBe(true);
    });
});

describe('useRules composable — failure and abort handling', () => {
    it('create(input) sets a German error, returns null, and does not throw on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { create, error } = useRules();

        const result = await create(createInput());
        await flushPromises();

        expect(result).toBeNull();
        expect(typeof error.value).toBe('string');
        expect(error.value).not.toBe('');
    });

    it('destroy(id) sets an error and returns false on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { destroy, error } = useRules();

        const result = await destroy(RULE_ID);
        await flushPromises();

        expect(result).toBe(false);
        expect(error.value).not.toBeNull();
    });

    it('preview(scope) sets an error without throwing on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { preview, error } = useRules();

        await preview({
            object_type_id: OBJECT_TYPE_ID,
            segment_id: null,
            filter_definition: null,
        });
        await flushPromises();

        expect(error.value).not.toBeNull();
    });

    it('create(input) resolves to null on an aborted/timed-out request without an uncaught rejection', async () => {
        const abortError = new Error('The operation was aborted');
        abortError.name = 'AbortError';
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.reject(abortError));
        vi.stubGlobal('fetch', fetchMock);

        const { create, error } = useRules();

        const result = await create(createInput());
        await flushPromises();

        expect(result).toBeNull();
        expect(error.value).not.toBeNull();
    });
});

describe('addLeadStage / removeLeadStage (pure helpers)', () => {
    it('addLeadStage grows the list with a new {value, unit} defaulting to unit "day"', () => {
        const grown = addLeadStage([]) as Array<{
            value: number;
            unit: string;
        }>;

        expect(grown).toHaveLength(1);
        expect(grown[0].unit).toBe('day');
        expect(typeof grown[0].value).toBe('number');
        expect(grown[0].value).toBeGreaterThanOrEqual(0);
    });

    it('addLeadStage preserves existing stages when appending', () => {
        const grown = addLeadStage([{ value: 2, unit: 'week' }]) as Array<{
            value: number;
            unit: string;
        }>;

        expect(grown).toHaveLength(2);
        expect(grown[0]).toEqual({ value: 2, unit: 'week' });
        expect(grown[1].unit).toBe('day');
    });

    it('removeLeadStage shrinks the list by dropping the entry at the given index', () => {
        const shrunk = removeLeadStage(
            [
                { value: 1, unit: 'day' },
                { value: 2, unit: 'week' },
                { value: 3, unit: 'month' },
            ],
            1,
        ) as Array<{ value: number; unit: string }>;

        expect(shrunk).toEqual([
            { value: 1, unit: 'day' },
            { value: 3, unit: 'month' },
        ]);
    });
});

describe('isFieldEncrypted / availableWatchFields (R-13 encrypted fields disabled)', () => {
    it('isFieldEncrypted is true only for a field flagged is_encrypted', () => {
        expect(isFieldEncrypted(makeField('ssn', { is_encrypted: true }))).toBe(
            true,
        );
        expect(isFieldEncrypted(makeField('name'))).toBe(false);
        expect(
            isFieldEncrypted(makeField('phone', { is_encrypted: false })),
        ).toBe(false);
    });

    it('availableWatchFields marks encrypted fields disabled while keeping plain fields selectable', () => {
        const fields = [
            makeField('name'),
            makeField('ssn', { is_encrypted: true }),
        ];

        const options = availableWatchFields(fields) as Array<{
            key: string;
            disabled: boolean;
        }>;

        expect(options).toHaveLength(2);

        const plain = options.find((option) => option.key === 'name');
        const encrypted = options.find((option) => option.key === 'ssn');

        expect(plain?.disabled).toBe(false);
        expect(encrypted?.disabled).toBe(true);
    });
});

describe('useRules composable — the reason the server gave', () => {
    it('create(input) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Das Feld ist verschlüsselt.',
                        errors: { 'config.date_field_key': ['egal'] },
                    }),
                ),
            ),
        );

        const { create, error } = useRules();

        await expect(create(createInput())).resolves.toBeNull();

        expect(error.value).toBe('Das Feld ist verschlüsselt.');
    });

    it('destroy(id) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(403, {
                        message: 'Sie dürfen diese Regel nicht löschen.',
                    }),
                ),
            ),
        );

        const { destroy, error } = useRules();

        await expect(destroy(RULE_ID)).resolves.toBe(false);

        expect(error.value).toBe('Sie dürfen diese Regel nicht löschen.');
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(500, {}))),
        );

        const { destroy, error } = useRules();

        await expect(destroy(RULE_ID)).resolves.toBe(false);

        expect(error.value).toBe(
            'Die Regel konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );
    });
});
