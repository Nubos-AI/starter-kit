import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, onMounted, ref } from 'vue';
import {
    clearPageBreadcrumbs,
    useBreadcrumbTrail,
} from '@/composables/useBreadcrumbs';
import { hydratePreferences } from '@/composables/useUserPreferences';
import { RELATIONS_PANEL_ID } from '@/lib/recordPanels';
import Form from '@/pages/records/Form.vue';
import type { FieldDefinition } from '@/types/fields';
import type { PreferenceDocument } from '@/types/preferences';
import { emptyObjectTypePreferences } from '@/types/preferences';
import type { RecordObjectType, RecordPayload } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'personal' });

const visit = vi.fn();
const reloadMock = vi.fn();

const { pageUrl } = vi.hoisted(() => ({
    pageUrl: { value: '/personal/records/rec-1' },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({
        scrollProps: {},
        url: pageUrl.value,
        props: { auth: { user: null, can: grantedAbilities, authority: null } },
    }),
    router: {
        on: vi.fn(() => vi.fn()),
        visit: (...args: unknown[]) => visit(...args),
        reload: (...args: unknown[]) => reloadMock(...args),
        get: vi.fn(),
    },
}));

vi.mock('@/composables/useUserOptions', () => ({
    useUserOptions: () => ({
        options: ref([
            { value: 'u-1', label: 'Anna Albers' },
            { value: 'u-2', label: 'Sven Support' },
            { value: 'u-3', label: 'Rita Vertrieb' },
        ]),
        loading: ref(false),
        error: ref(null),
        load: vi.fn(),
    }),
}));

vi.mock('@/lib/recordToast', () => ({ recordToast: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

const objectType: RecordObjectType = {
    id: 'ot-1',
    key: 'deals',
    slug: 'deals',
    name: 'Deals',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const fieldDefinitions: FieldDefinition[] = [
    {
        key: 'titel',
        field_type: 'text_short',
        label: 'Titel',
        is_required: true,
    },
];

const record = {
    id: '01RECORD0K5N3Q8V9WYE6M2H7X',
    objectTypeId: 'ot-1',
    recordNumber: 'REC-1',
    title: 'Angebot A',
    externalReferenceId: null,
    version: 3,
    data: { titel: 'Angebot A' },
} as unknown as RecordPayload;

const DynamicFormStub = {
    name: 'DynamicFormStub',
    props: ['fields', 'modelValue', 'errors', 'hiddenSections'],
    emits: ['update:modelValue'],
    template:
        '<div data-dynamic-form :data-errors="JSON.stringify(errors)" :data-hidden="(hiddenSections ?? []).join(\',\')" @click.self="$emit(\'update:modelValue\', { titel: \'Angebot B\' })"><slot /></div>',
};

const stubs = {
    teleport: true,
    DynamicForm: DynamicFormStub,
    ConflictDialog: { template: '<div data-conflict-dialog />' },
    UnsavedChangesDialog: { template: '<div data-unsaved-dialog />' },
    RecordTimeline: {
        props: ['recordId', 'fields'],
        methods: { reload: () => undefined },
        template:
            '<div data-record-timeline :data-record-id="recordId" :data-fields="JSON.stringify(fields)" />',
    },
    RecordDetailTabs: {
        props: ['recordId', 'readonly'],
        template:
            '<div data-record-detail-tabs :data-record-id="recordId" :data-readonly="readonly" />',
    },
    RecordWatchersMenu: {
        props: ['recordId', 'options', 'disabled'],
        template:
            '<div data-record-watchers-menu :data-disabled="disabled" :data-options="options.map((option) => option.value).join(\',\')" />',
    },
    RecordOwnerMenu: {
        props: ['modelValue', 'options', 'disabled'],
        emits: ['update:modelValue'],
        template:
            '<button data-record-owner-menu :data-disabled="disabled" @click="$emit(\'update:modelValue\', \'u-9\')" />',
    },
    RecordCollaboratorsMenu: {
        props: ['modelValue', 'options', 'disabled'],
        emits: ['update:modelValue'],
        template:
            '<button data-record-collaborators-menu :data-disabled="disabled" :data-options="options.map((option) => option.value).join(\',\')" @click="$emit(\'update:modelValue\', [\'u-7\'])" />',
    },
    RecordActionsMenu: {
        props: ['trashed', 'canDelete', 'canMerge', 'canManagePanels'],
        emits: ['manage-panels', 'duplicate', 'merge', 'delete'],
        template:
            '<div data-record-actions-trigger :data-can-merge="canMerge ? \'\' : undefined">' +
            '<button v-if="canManagePanels" data-record-action="panels" @click="$emit(\'manage-panels\')" />' +
            '<button data-record-action="duplicate" @click="$emit(\'duplicate\')" />' +
            '<button data-record-action="merge" @click="$emit(\'merge\')" />' +
            '<button data-record-action="delete" @click="$emit(\'delete\')" />' +
            '</div>',
    },
    ConfirmDialog: {
        props: ['open', 'pending', 'title', 'description'],
        emits: ['confirm', 'cancel'],
        template:
            '<div v-if="open" data-confirm-dialog :data-confirm-title="title">' +
            '{{ description }}<slot />' +
            '<button data-confirm @click="$emit(\'confirm\')" />' +
            '<button data-confirm-cancel @click="$emit(\'cancel\')" /></div>',
    },
    RecordRelations: {
        props: ['recordId', 'readonly'],
        template:
            '<div data-record-relations :data-readonly="readonly ? \'\' : undefined" />',
    },
    RecordPanelSettings: {
        props: ['open', 'panels', 'objectTypeId'],
        emits: ['close'],
        template:
            '<div v-if="open" data-record-panel-settings :data-panels="panels.map((panel) => panel.id).join(\',\')" />',
    },
    Teleport: true,
};

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

const grantedAbilities: Record<string, boolean> = {};

function preferenceDocument(panelVisibility: boolean): PreferenceDocument {
    return {
        settings: {
            appearance: 'system',
            density: 'compact',
            pageSize: 100,
            sidebarOpen: true,
            startObjectTypeId: null,
        },
        objectTypes: {},
        grids: {},
        policy: {
            columnsAndSorting: { records: true, configuration: true },
            viewMode: { records: true },
            filterAndSegment: { records: false },
            layoutAndAppearance: { global: true },
            panelState: { records: true },
            panelVisibility: { records: panelVisibility },
        },
    };
}

function mountForm(
    mode: 'create' | 'edit',
    overrides: Record<string, unknown> = {},
    granted: string[] = [],
) {
    for (const key of Object.keys(grantedAbilities)) {
        delete grantedAbilities[key];
    }

    for (const ability of granted) {
        grantedAbilities[`${objectType.slug}.${ability}`] = true;
    }

    return mount(Form, {
        props: {
            mode,
            objectType,
            fieldDefinitions,
            record: mode === 'edit' ? record : null,
            hasRelationships: true,
            ...overrides,
        },
        global: { stubs },
    });
}

interface SeedingContext {
    seedValues: (data: Record<string, unknown>) => void;
}

const contactType: RecordObjectType = {
    id: 'ot-1',
    key: 'contacts',
    slug: 'contacts',
    name: 'Kontakte',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const contactFields: FieldDefinition[] = [
    { key: 'name', field_type: 'text_short', label: 'Name', is_required: true },
    { key: 'email', field_type: 'email', label: 'E-Mail', is_required: false },
];

const contactRecord = {
    id: '01RECORD0K5N3Q8V9WYE6M2H7X',
    objectTypeId: 'ot-1',
    recordNumber: 'CON-1',
    title: 'Erika Muster',
    externalReferenceId: null,
    version: 3,
    data: { name: 'Erika Muster', email: '' },
} as unknown as RecordPayload;

const seed = { name: 'Max Mail', email: 'max@example.test' };

function seedingExtension(when: 'setup' | 'mounted' | 'click') {
    return defineComponent({
        props: {
            name: { type: String, required: true },
            context: { type: Object, required: true },
        },
        setup(props) {
            if (props.name !== 'records.form') {
                return () => null;
            }

            const context = (): SeedingContext =>
                props.context as unknown as SeedingContext;

            if (when === 'setup') {
                context().seedValues(seed);
            }

            onMounted(() => {
                if (when === 'mounted') {
                    context().seedValues(seed);
                }
            });

            return () =>
                h('button', {
                    'data-seed': '',
                    onClick: () => context().seedValues(seed),
                });
        },
    });
}

function mountSeededForm(
    mode: 'create' | 'edit',
    when: 'setup' | 'mounted' | 'click',
    data: Record<string, unknown> = {},
) {
    return mount(Form, {
        props: {
            mode,
            objectType: contactType,
            fieldDefinitions: contactFields,
            record: mode === 'edit' ? { ...contactRecord, data } : null,
        },
        global: {
            stubs: {
                teleport: true,
                UiExtensionPoint: seedingExtension(when),
                DynamicForm: {
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template:
                        '<div data-dynamic-form :data-values="JSON.stringify(modelValue)" @click.self="$emit(\'update:modelValue\', { ...modelValue, name: \'Eigene Eingabe\' })"><slot /></div>',
                },
                RecordTimeline: { template: '<div />' },
                RecordDetailTabs: { template: '<div />' },
                RecordWatchersMenu: { template: '<div />' },
                RecordOwnerMenu: { template: '<div />' },
                RecordCollaboratorsMenu: { template: '<div />' },
                RecordActionsMenu: { template: '<div />' },
                RecordRelations: { template: '<div />' },
                ConflictDialog: { template: '<div />' },
                UnsavedChangesDialog: { template: '<div />' },
            },
        },
    });
}

function formValues(wrapper: ReturnType<typeof mountSeededForm>): unknown {
    return JSON.parse(
        String(wrapper.get('[data-dynamic-form]').attributes('data-values')),
    );
}

function isSaveEnabled(wrapper: ReturnType<typeof mountSeededForm>): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') === undefined;
}

beforeEach(() => {
    hydratePreferences(preferenceDocument(true));
    document.cookie = 'XSRF-TOKEN=test-xsrf-token';
    pageUrl.value = `/personal/records/${record.id}`;
    reloadMock.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('records/Form — extensions seed the values of a new record', () => {
    it.each(['setup', 'mounted'] as const)(
        'takes values seeded while the extension %s runs and enables saving',
        async (when) => {
            const wrapper = mountSeededForm('create', when);
            await flushPromises();

            expect(formValues(wrapper)).toEqual(seed);
            expect(isSaveEnabled(wrapper)).toBe(true);
        },
    );

    it('takes values seeded after the page is shown and enables saving', async () => {
        const wrapper = mountSeededForm('create', 'click');
        await flushPromises();

        expect(isSaveEnabled(wrapper)).toBe(false);

        await wrapper.get('[data-seed]').trigger('click');

        expect(formValues(wrapper)).toEqual(seed);
        expect(isSaveEnabled(wrapper)).toBe(true);
    });

    it('keeps what the user already entered and only fills the blank fields', async () => {
        const wrapper = mountSeededForm('create', 'click');
        await flushPromises();
        await wrapper.get('[data-dynamic-form]').trigger('click');
        await wrapper.get('[data-seed]').trigger('click');

        expect(formValues(wrapper)).toEqual({
            name: 'Eigene Eingabe',
            email: 'max@example.test',
        });
    });

    it('ignores seeded values on an existing record', async () => {
        const wrapper = mountSeededForm('edit', 'mounted', contactRecord.data);
        await flushPromises();
        await wrapper.get('[data-seed]').trigger('click');

        expect(formValues(wrapper)).toEqual(contactRecord.data);
        expect(isSaveEnabled(wrapper)).toBe(false);
    });
});

describe('records/Form — page shell', () => {
    it('names the record in the breadcrumb so the header leads back', async () => {
        clearPageBreadcrumbs();

        mountForm('edit');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Deals', href: '/personal/records/deals' },
            { title: 'Angebot A' },
        ]);
    });

    it('announces the create intent', async () => {
        expect(mountForm('create').text()).toContain('Deals anlegen');
    });

    it('announces the edit intent with the record title', async () => {
        expect(mountForm('edit').text()).toContain('Angebot A');
    });
});

describe('records/Form — the detail area', () => {
    it('carries the detail tabs but no history tab', async () => {
        const wrapper = mountForm('edit', {}, ['audit.view']);
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Verlauf');
    });

    it.each<[string, boolean, string]>([
        ['leaves the detail tabs writable on a live record', false, 'false'],
        ['locks the detail tabs on a deleted record', true, 'true'],
    ])('%s', async (_name, trashed, readonly) => {
        expect(
            mountForm('edit', { trashed })
                .get('[data-record-detail-tabs]')
                .attributes('data-readonly'),
        ).toBe(readonly);
    });

    it('locks the form and drops the save row without the update permission', async () => {
        const wrapper = mountForm('edit', { canUpdate: false });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-readonly]').exists()).toBe(true);
        expect(wrapper.find('[data-form-save]').exists()).toBe(false);
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
    });

    it('still lets a read-only viewer work in the tabs and read the timeline', async () => {
        const wrapper = mountForm('edit', { canUpdate: false });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            wrapper
                .get('[data-record-detail-tabs]')
                .attributes('data-readonly'),
        ).toBe('false');
        expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
    });

    it('locks the people menus for a viewer without the update permission', async () => {
        const wrapper = mountForm('edit', { canUpdate: false });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            wrapper.get('[data-record-owner-menu]').attributes('data-disabled'),
        ).toBe('true');
        expect(
            wrapper
                .get('[data-record-collaborators-menu]')
                .attributes('data-disabled'),
        ).toBe('true');
    });

    it('explains a deleted record and blocks its save row', async () => {
        const wrapper = mountForm('edit', { trashed: true });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-trashed]').exists()).toBe(true);
        expect(wrapper.find('[data-form-save]').exists()).toBe(false);
        expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
    });
});

describe('records/Form — relations box', () => {
    it('puts the relations above the data of a saved record', async () => {
        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();
        const main = wrapper.get('[data-record-detail-main]');
        const html = main.html();

        expect(main.find('[data-record-relations]').exists()).toBe(true);
        expect(html.indexOf('data-record-relations')).toBeLessThan(
            html.indexOf('data-dynamic-form'),
        );
    });

    it('keeps the box away entirely when the object type has no relationship types', async () => {
        const wrapper = mountForm('edit', { hasRelationships: false });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
    });

    it('hands the trashed state down as read-only', async () => {
        const wrapper = mountForm('edit', { trashed: true });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            wrapper.get('[data-record-relations]').attributes('data-readonly'),
        ).toBe('');
    });
});

describe('records/Form — one view for creating and editing', () => {
    it('a record being created shows only the fields and the pending hint', async () => {
        const wrapper = mountForm('create');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-timeline]').exists()).toBe(false);
        expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(false);
        expect(wrapper.find('[data-record-reminders]').exists()).toBe(false);
        expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
        expect(wrapper.find('[data-record-people]').exists()).toBe(false);
        expect(wrapper.find('[data-record-actions-trigger]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-dynamic-form]').exists()).toBe(true);
        expect(wrapper.get('[data-record-detail-pending]').text()).toContain(
            'nach dem ersten Speichern',
        );
    });

    it('keeps the hint away from a saved record', async () => {
        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-detail-pending]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
    });
});

describe('records/Form — saving', () => {
    it('keeps the save button disabled until something changes', async () => {
        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            wrapper.find('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await wrapper.find('[data-dynamic-form]').trigger('click');

        expect(
            wrapper.find('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('updates the record and stays on the record', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { ...record, version: 4 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.find('[data-dynamic-form]').trigger('click');
        await wrapper.find('[data-form-save]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        const [url, init] = (
            fetchMock.mock.calls as unknown as Array<[string, RequestInit]>
        ).find(([, options]) => options?.method === 'PUT') as [
            string,
            RequestInit,
        ];

        expect(url).toBe(`/personal/engine/records/${record.id}`);
        expect(JSON.parse(String(init.body))).toEqual({
            data: { titel: 'Angebot B' },
            version: 3,
            owner_id: null,
            collaborator_ids: [],
        });
        expect(visit).not.toHaveBeenCalled();
    });

    it('keeps writing with the version the server answered with', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { ...record, version: 4 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.find('[data-dynamic-form]').trigger('click');
        await wrapper.find('[data-form-save]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        await wrapper.find('[data-record-owner-menu]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        const bodies = (
            fetchMock.mock.calls as unknown as Array<[string, RequestInit]>
        )
            .filter(([, options]) => options?.method === 'PUT')
            .map(([, options]) => JSON.parse(String(options.body)).version);

        expect(bodies).toEqual([3, 4]);
    });

    it('creates a record through the object type endpoint', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(201, { data: record })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('create');
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.find('[data-dynamic-form]').trigger('click');
        await wrapper.find('[data-form-save]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/personal/engine/records/deals');
        expect(init.method).toBe('POST');
        expect(visit).toHaveBeenCalledWith(
            `/personal/records/${record.recordNumber}/edit`,
        );
    });

    it('binds the server field error to the rendered field key', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'titel is required.',
                        errors: { 'data.titel': ['Pflichtfeld.'] },
                    }),
                ),
            ),
        );

        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.find('[data-dynamic-form]').trigger('click');
        await wrapper.find('[data-form-save]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            JSON.parse(
                String(
                    wrapper
                        .find('[data-dynamic-form]')
                        .attributes('data-errors'),
                ),
            ),
        ).toEqual({ titel: 'Pflichtfeld.' });
        expect(visit).not.toHaveBeenCalled();
    });
});

describe('records/Form — the header acts on the record', () => {
    function editRecord(): RecordPayload {
        return { ...record, ownerId: 'u-1' } as unknown as RecordPayload;
    }

    function callWith(
        fetchMock: ReturnType<typeof vi.fn>,
        method: string,
    ): [string, RequestInit] {
        return (
            fetchMock.mock.calls as unknown as Array<[string, RequestInit]>
        ).find(([, options]) => options?.method === method) as [
            string,
            RequestInit,
        ];
    }

    it('saves a new owner right away instead of waiting for the save button', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(200, { data: { ...record, version: 4 } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('edit', { record: editRecord() });
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.get('[data-record-owner-menu]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        const [url, init] = callWith(fetchMock, 'PUT');

        expect(init.method).toBe('PUT');
        expect(url).toContain(record.id);
        expect(JSON.parse(String(init.body))).toEqual({
            version: record.version,
            owner_id: 'u-9',
            collaborator_ids: [],
        });
    });

    it('leaves the save row untouched when only the assignment changed', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(200, { data: { ...record, version: 4 } }),
                ),
            ),
        );

        const wrapper = mountForm('edit', { record: editRecord() });
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.get('[data-record-collaborators-menu]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            wrapper.find('[data-form-save]').attributes('disabled'),
        ).toBeDefined();
    });

    it.each<[string, string | null, string, string]>([
        [
            'duplicates the record and opens the copy',
            'CO-0000000007',
            'copy-1',
            '/personal/records/CO-0000000007/edit',
        ],
        [
            'opens a copy without a business key by its id',
            null,
            'copy-2',
            '/personal/records/copy-2/edit',
        ],
    ])('%s', async (_name, recordNumber, id, expectedUrl) => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(201, { data: { ...record, id, recordNumber } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.get('[data-record-action="duplicate"]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        const [url, init] = callWith(fetchMock, 'POST');

        expect(init.method).toBe('POST');
        expect(url).toContain('duplicate');
        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit).toHaveBeenCalledWith(expectedUrl);
    });

    it('asks before deleting and returns to the list afterwards', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(jsonResponse(204, {})));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-confirm-dialog]').exists()).toBe(false);

        await wrapper.get('[data-record-action="delete"]').trigger('click');

        expect(wrapper.find('[data-confirm-dialog]').exists()).toBe(true);
        expect(callWith(fetchMock, 'DELETE')).toBeUndefined();

        await wrapper.get('[data-confirm]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(callWith(fetchMock, 'DELETE')).toBeDefined();
        expect(visit).toHaveBeenCalledWith(
            expect.stringContaining(objectType.slug),
        );
    });
});

describe('records/Form — a person holds one role at a time', () => {
    const people = [
        { value: 'u-1', label: 'Anna Albers' },
        { value: 'u-2', label: 'Sven Support' },
        { value: 'u-3', label: 'Rita Vertrieb' },
    ];

    function optionsOf(
        wrapper: ReturnType<typeof mountForm>,
        hook: string,
    ): string[] {
        const raw = wrapper.get(hook).attributes('data-options') ?? '';

        return raw === '' ? [] : raw.split(',');
    }

    function mountWithPeople(
        collaborators: string[] = [],
    ): ReturnType<typeof mountForm> {
        return mountForm('edit', {
            record: { ...record, ownerId: 'u-1' } as unknown as RecordPayload,
            collaborators: collaborators.map((value) => ({
                value,
                label: value,
            })),
        });
    }

    it('keeps the owner out of the collaborator list', async () => {
        const wrapper = mountWithPeople();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            optionsOf(wrapper, '[data-record-collaborators-menu]'),
        ).not.toContain('u-1');
    });

    it('keeps owner and collaborators out of the watcher list', async () => {
        const wrapper = mountWithPeople(['u-2']);
        await vi.dynamicImportSettled();
        await flushPromises();

        const options = optionsOf(wrapper, '[data-record-watchers-menu]');

        expect(options).not.toContain('u-1');
        expect(options).not.toContain('u-2');
        expect(options).toContain('u-3');
    });

    it('drops a collaborator who becomes the owner', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(200, { data: { ...record, version: 4 } }),
                ),
            ),
        );

        const wrapper = mountWithPeople(['u-9']);
        await vi.dynamicImportSettled();
        await flushPromises();

        await wrapper.get('[data-record-owner-menu]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(
            optionsOf(wrapper, '[data-record-collaborators-menu]'),
        ).not.toContain('u-9');
    });

    it('offers everyone again once the record has no owner', async () => {
        const wrapper = mountForm('edit', {
            record: { ...record, ownerId: null } as unknown as RecordPayload,
        });
        await vi.dynamicImportSettled();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(optionsOf(wrapper, '[data-record-collaborators-menu]')).toEqual(
            people.map((person) => person.value),
        );
    });
});

describe('records/Form — the panels the user keeps', () => {
    it('opens the panel settings from the actions menu', async () => {
        const wrapper = mountForm('edit');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-panel-settings]').exists()).toBe(
            false,
        );

        await wrapper.get('[data-record-action="panels"]').trigger('click');

        expect(
            wrapper
                .get('[data-record-panel-settings]')
                .attributes('data-panels'),
        ).toBe([RELATIONS_PANEL_ID, 'group:ungrouped'].join(','));
    });

    it('leaves out every panel the user switched off', async () => {
        const wrapper = mountForm('edit', {
            preference: {
                ...emptyObjectTypePreferences(),
                hiddenSections: [RELATIONS_PANEL_ID, 'group:ungrouped'],
            },
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
        expect(
            wrapper.get('[data-dynamic-form]').attributes('data-hidden'),
        ).toBe([RELATIONS_PANEL_ID, 'group:ungrouped'].join(','));
    });

    it.each<[string, string, boolean]>([
        [
            'takes the save row away once no field section is left to save',
            'group:ungrouped',
            false,
        ],
        [
            'keeps the save row while a field section is visible',
            RELATIONS_PANEL_ID,
            true,
        ],
    ])('%s', async (_name, hiddenSection, showsSaveRow) => {
        const wrapper = mountForm('edit', {
            preference: {
                ...emptyObjectTypePreferences(),
                hiddenSections: [hiddenSection],
            },
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-form-actions]').exists()).toBe(showsSaveRow);
    });

    it('never takes the aside panels away from the user', async () => {
        const wrapper = mountForm('edit', {
            preference: {
                ...emptyObjectTypePreferences(),
                hiddenSections: ['reminders', 'timeline'],
            },
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(true);
        expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
    });

    it.each<[string, 'create' | 'edit', boolean]>([
        [
            'offers no panel settings while the record is still being created',
            'create',
            true,
        ],
        [
            'offers no panel settings once the tenant switched the category off',
            'edit',
            false,
        ],
    ])('%s', async (_name, mode, allowsPanelVisibility) => {
        hydratePreferences(preferenceDocument(allowsPanelVisibility));

        const wrapper = mountForm(mode);
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-action="panels"]').exists()).toBe(
            false,
        );
    });
});

describe('records/Form — merging', () => {
    it('opens the merge page from the actions menu', async () => {
        const wrapper = mountForm('edit', {}, ['update', 'merge']);
        await vi.dynamicImportSettled();
        await flushPromises();

        await wrapper.get('[data-record-action="merge"]').trigger('click');

        expect(visit).toHaveBeenCalledTimes(1);
        expect(String(visit.mock.calls[0][0])).toContain(
            `/records/${record.id}/merge`,
        );
    });

    it('tells the menu whether this user may merge at all', async () => {
        expect(
            mountForm('edit', {}, ['update', 'merge'])
                .get('[data-record-actions-trigger]')
                .attributes('data-can-merge'),
        ).toBeDefined();

        expect(
            mountForm('edit', {}, ['update'])
                .get('[data-record-actions-trigger]')
                .attributes('data-can-merge'),
        ).toBeUndefined();
    });

    it('offers to take a recent merge back and reloads once it went through', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(200, {
                        data: {
                            merge_id: 'm-1',
                            target_id: record.id,
                            source_id: 'src-1',
                            restored_fields: [],
                            kept_fields: [],
                            restored_transfers: {},
                            unrecoverable: {},
                        },
                    }),
                ),
            ),
        );

        const wrapper = mountForm(
            'edit',
            {
                undoableMerge: {
                    id: 'm-1',
                    sourceId: 'src-1',
                    mergedAt: '2026-08-26T10:00:00.000Z',
                    undoableUntil: '2099-01-01T00:00:00.000Z',
                },
            },
            ['update', 'merge'],
        );
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-merge-undo]').exists()).toBe(true);

        await wrapper.get('[data-record-merge-undo-button]').trigger('click');
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(reloadMock).toHaveBeenCalledTimes(1);
    });

    it('lets the user put the undo offer away', async () => {
        const wrapper = mountForm(
            'edit',
            {
                undoableMerge: {
                    id: 'm-1',
                    sourceId: 'src-1',
                    mergedAt: '2026-08-26T10:00:00.000Z',
                    undoableUntil: '2099-01-01T00:00:00.000Z',
                },
            },
            ['update', 'merge'],
        );
        await vi.dynamicImportSettled();
        await flushPromises();

        await wrapper
            .get('[data-testid="record-merge-undo-dismiss"]')
            .trigger('click');

        expect(wrapper.find('[data-record-merge-undo]').exists()).toBe(false);
    });

    it('names the day the undo offer runs out', async () => {
        const notice = mountForm(
            'edit',
            {
                undoableMerge: {
                    id: 'm-1',
                    sourceId: 'src-1',
                    mergedAt: '2026-08-26T10:00:00.000Z',
                    undoableUntil: '2099-01-01T00:00:00.000Z',
                },
            },
            ['update', 'merge'],
        ).get('[data-record-merge-undo-deadline]');

        expect(notice.text()).toContain('2099');
    });

    it.each<[string, Record<string, unknown>]>([
        [
            'takes the undo offer away once its window has run out',
            {
                undoableMerge: {
                    id: 'm-1',
                    sourceId: 'src-1',
                    mergedAt: '2020-01-01T10:00:00.000Z',
                    undoableUntil: '2020-01-31T10:00:00.000Z',
                },
            },
        ],
        ['shows no undo offer when there is no recent merge', {}],
    ])('%s', async (_name, overrides) => {
        expect(
            mountForm('edit', overrides, ['update', 'merge'])
                .find('[data-record-merge-undo]')
                .exists(),
        ).toBe(false);
    });

    it('points a record that was merged away at its survivor', async () => {
        const wrapper = mountForm(
            'edit',
            {
                record: { ...record, mergedIntoRecordId: 'winner-1' },
                trashed: true,
            },
            ['update', 'merge'],
        );
        await vi.dynamicImportSettled();
        await flushPromises();

        const banner = wrapper.get('[data-record-merged]');

        expect(banner.text()).toContain('zusammengeführt');
        expect(banner.get('a').attributes('href')).toContain('winner-1');
        expect(wrapper.find('[data-record-trashed]').exists()).toBe(false);
    });
});

describe('records/Form — a deleted record announces its purge deadline', () => {
    it.each<[string, string | null, string]>([
        [
            'names the deadline when the object type keeps a retention period',
            '2026-09-11',
            'Endgültige Löschung am 11.09.2026',
        ],
        [
            'says the record simply stays without a retention period',
            null,
            'bis auf Weiteres',
        ],
    ])('%s', async (_name, purgeOn, notice) => {
        const wrapper = mountForm('edit', { trashed: true, purgeOn });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-purge-notice]').text()).toContain(
            notice,
        );
    });

    it('shows the recorded deletion reason', async () => {
        const wrapper = mountForm('edit', {
            trashed: true,
            deletionReason: 'Dublette',
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-deletion-reason]').text()).toContain(
            'Dublette',
        );
    });

    it('keeps the people menus away so a deleted record fires no write lookups', async () => {
        const wrapper = mountForm('edit', { trashed: true });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-record-owner-menu]').exists()).toBe(false);
        expect(wrapper.find('[data-record-collaborators-menu]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-record-watchers-menu]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-record-actions-trigger]').exists()).toBe(
            true,
        );
    });

    it.each<[string, boolean]>([
        ['hides the purge button without the right', false],
        ['offers the purge button with the right', true],
    ])('%s', async (_name, canPurge) => {
        const wrapper = mountForm('edit', { trashed: true, canPurge });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('[data-testid="record-purge"]').exists()).toBe(
            canPurge,
        );
    });
});

describe('records/Form — deleting asks for a reason when the type demands one', () => {
    it.each<[string, boolean]>([
        [
            'shows no reason field when the object type does not demand one',
            false,
        ],
        ['shows the reason field when the object type demands one', true],
    ])('%s', async (_name, requiresDeletionReason) => {
        const wrapper = mountForm(
            'edit',
            { objectType: { ...objectType, requiresDeletionReason } },
            ['delete'],
        );
        await vi.dynamicImportSettled();
        await flushPromises();

        await wrapper.find('[data-record-action="delete"]').trigger('click');

        expect(wrapper.find('[data-testid="deletion-reason"]').exists()).toBe(
            requiresDeletionReason,
        );
    });
});
