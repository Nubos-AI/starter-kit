import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import QuickCreate from '@/components/engine/search/QuickCreate.vue';
import { CommandDialog, CommandList } from '@/components/ui/command';
import { show } from '@/routes/engine/records';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const { visitMock } = vi.hoisted(() => ({ visitMock: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: visitMock },
    usePage: () => ({ props: {} }),
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

vi.mock('@/lib/recordToast', () => ({
    recordToast: vi.fn(),
}));

if (typeof Element.prototype.scrollIntoView !== 'function') {
    Element.prototype.scrollIntoView = vi.fn();
}

interface CreatableType extends RecordObjectType {
    fieldDefinitions: FieldDefinition[];
}

function field(key: string, label: string): FieldDefinition {
    return {
        key,
        field_type: 'text_short',
        label,
        is_required: false,
        description: null,
    };
}

const CATALOG: CreatableType[] = [
    {
        id: 'ot-company',
        key: 'company',
        slug: 'company',
        name: 'Company',
        requiresDeletionReason: false,
        hasHierarchy: false,
        fieldDefinitions: [field('name', 'Name'), field('domain', 'Domain')],
    },
    {
        id: 'ot-person',
        key: 'person',
        slug: 'person',
        name: 'Person',
        requiresDeletionReason: false,
        hasHierarchy: false,
        fieldDefinitions: [field('email', 'Email')],
    },
];

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

interface SaveOutcome {
    status: number;
    body: unknown;
}

function catalogAndSaveFetch(
    catalog: CreatableType[],
    save: SaveOutcome,
): ReturnType<typeof vi.fn> {
    return vi.fn((_url: string, init?: RequestInit) => {
        const method = String(init?.method ?? 'GET').toUpperCase();

        if (method === 'POST') {
            return Promise.resolve(jsonResponse(save.status, save.body));
        }

        return Promise.resolve(jsonResponse(200, catalog));
    });
}

const OK_SAVE: SaveOutcome = { status: 200, body: {} };

let wrapper: ReturnType<typeof mount> | null = null;

const Host = defineComponent({
    render() {
        return h(
            CommandDialog,
            { open: true },
            {
                default: () =>
                    h(CommandList, {}, { default: () => h(QuickCreate) }),
            },
        );
    },
});

function mountQuickCreate(): ReturnType<typeof mount> {
    wrapper = mount(Host, { attachTo: document.body });

    return wrapper;
}

async function settle(): Promise<void> {
    await vi.advanceTimersByTimeAsync(1);
    await flushPromises();
}

function typeItems(): HTMLElement[] {
    return Array.from(
        document.querySelectorAll<HTMLElement>('[data-quick-create-type]'),
    );
}

function typeItem(slug: string): HTMLElement {
    const item = document.querySelector<HTMLElement>(
        `[data-quick-create-type][data-object-type-slug="${slug}"]`,
    );

    if (item === null) {
        throw new Error(`quick-create type "${slug}" not found in the palette`);
    }

    return item;
}

function click(element: HTMLElement): void {
    element.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
    );
}

function formRegion(): Element | null {
    return document.querySelector('[data-quick-create-form]');
}

async function selectType(slug: string): Promise<void> {
    click(typeItem(slug));
    await settle();
}

async function save(): Promise<void> {
    const button = document.querySelector<HTMLElement>(
        '[data-quick-create-save]',
    );

    if (button === null) {
        throw new Error('quick-create save affordance not found');
    }

    click(button);
    await settle();
}

beforeEach(() => {
    vi.useFakeTimers();
    visitMock.mockReset();
});

afterEach(() => {
    if (wrapper !== null) {
        wrapper.unmount();
        wrapper = null;
    }

    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('QuickCreate — Erstellen group (SC-8)', () => {
    it('lists exactly the creatable types returned by the catalog fetch', async () => {
        vi.stubGlobal('fetch', catalogAndSaveFetch(CATALOG, OK_SAVE));
        mountQuickCreate();
        await settle();

        const slugs = typeItems().map((item) =>
            item.getAttribute('data-object-type-slug'),
        );

        expect(slugs).toEqual(['company', 'person']);
        expect(document.body.textContent).toContain('Company');
        expect(document.body.textContent).toContain('Person');
    });
});

describe('QuickCreate — catalog fetch failure (resilience)', () => {
    it('renders no creatable types and does not crash when the catalog GET is not ok', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(500, { message: 'boom' })),
        );
        vi.stubGlobal('fetch', fetchMock);

        mountQuickCreate();
        await settle();

        expect(typeItems()).toHaveLength(0);
        expect(document.querySelector('[data-quick-create]')).not.toBeNull();
        expect(
            document.querySelector('[data-quick-create-catalog]'),
        ).not.toBeNull();
        expect(formRegion()).toBeNull();
    });
});

describe('QuickCreate — lazy-mounted form (D4.2 / R-13)', () => {
    it('does not render DynamicForm before a type is chosen and renders the full form after', async () => {
        vi.stubGlobal('fetch', catalogAndSaveFetch(CATALOG, OK_SAVE));
        mountQuickCreate();
        await settle();

        expect(formRegion()).toBeNull();
        expect(document.querySelector('#name')).toBeNull();
        expect(document.querySelector('#domain')).toBeNull();

        await selectType('company');

        expect(formRegion()).not.toBeNull();
        expect(document.querySelector('#name')).not.toBeNull();
        expect(document.querySelector('#domain')).not.toBeNull();
    });
});

describe('QuickCreate — successful save navigates to detail (D4.3)', () => {
    it('visits the record detail route for the id returned on 201', async () => {
        vi.stubGlobal(
            'fetch',
            catalogAndSaveFetch(CATALOG, {
                status: 201,
                body: {
                    data: {
                        id: 'rec-new',
                        objectTypeId: 'ot-company',
                        stageId: null,
                        recordNumber: null,
                        externalReferenceId: null,
                        version: 1,
                        data: {},
                    },
                },
            }),
        );
        mountQuickCreate();
        await settle();

        await selectType('company');
        await save();

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock).toHaveBeenCalledWith(show.url({ record: 'rec-new' }));
    });
});

describe('QuickCreate — 422 field errors (server authority)', () => {
    it('renders the server error against the bare field key, proving the data. prefix is stripped', async () => {
        vi.stubGlobal(
            'fetch',
            catalogAndSaveFetch(CATALOG, {
                status: 422,
                body: {
                    message: 'The given data was invalid.',
                    errors: {
                        'data.name': ['Name ist ein Pflichtfeld.'],
                    },
                },
            }),
        );
        mountQuickCreate();
        await settle();

        await selectType('company');
        await save();

        const nameError = document.querySelector('#name-error');
        expect(nameError).not.toBeNull();
        expect(nameError?.textContent).toContain('Name ist ein Pflichtfeld.');

        expect(document.querySelector('#domain-error')).toBeNull();
        expect(visitMock).not.toHaveBeenCalled();
    });
});
