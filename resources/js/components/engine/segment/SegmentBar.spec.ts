import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SegmentBar from '@/components/engine/segment/SegmentBar.vue';
import SegmentSaveDialog from '@/components/engine/segment/SegmentSaveDialog.vue';
import SegmentShareDialog from '@/components/engine/segment/SegmentShareDialog.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import type { SegmentSummary } from '@/composables/useSegments';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const passthrough = { template: '<div><slot /></div>' };

const ButtonStub = {
    props: ['disabled'],
    emits: ['click'],
    template:
        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const InputStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
        '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const FilterBuilderStub = {
    name: 'FilterBuilder',
    props: ['fields', 'modelValue', 'showActions'],
    emits: ['apply', 'reset', 'update:modelValue'],
    template: '<div data-filterbuilder-stub></div>',
};

const stubs = {
    Select: passthrough,
    SelectContent: passthrough,
    SelectGroup: passthrough,
    SelectItem: passthrough,
    SelectItemText: passthrough,
    SelectLabel: passthrough,
    SelectTrigger: passthrough,
    SelectValue: passthrough,
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
    DialogClose: passthrough,
    ToggleGroup: passthrough,
    ToggleGroupItem: passthrough,
    Label: passthrough,
    Skeleton: passthrough,
    Badge: passthrough,
    Table: passthrough,
    TableHeader: passthrough,
    TableBody: passthrough,
    TableRow: passthrough,
    TableHead: passthrough,
    TableCell: passthrough,
    Button: ButtonStub,
    Input: InputStub,
    FilterBuilder: FilterBuilderStub,
};

const objectType: RecordObjectType = {
    id: 'ot-company',
    key: 'company',
    slug: 'companies',
    name: 'Companies',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const fields: FieldDefinition[] = [
    {
        key: 'name',
        field_type: 'text_short',
        label: 'Name',
        is_required: false,
        is_filterable: true,
    },
    {
        key: 'amount',
        field_type: 'number',
        label: 'Amount',
        is_required: false,
        is_filterable: true,
    },
];

function summary(overrides: Partial<SegmentSummary>): SegmentSummary {
    return {
        id: 'seg-x',
        name: 'Segment X',
        object_type_id: 'ot-company',
        is_system: false,
        is_default: false,
        is_owner: false,
        ...overrides,
    };
}

function fakeResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function stubFetch(response: Response): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockResolvedValue(response);
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountBar(segments: SegmentSummary[]) {
    const fetchMock = stubFetch(fakeResponse(200, { data: segments }));

    const wrapper = mount(SegmentBar, {
        props: { objectType, fields },
        global: { stubs },
    });

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await flushPromises();

    return { wrapper, fetchMock };
}

async function mountActiveBar(
    segments: SegmentSummary[],
    activeSegmentId: string,
    extraProps: Record<string, unknown> = {},
): Promise<VueWrapper> {
    const fetchMock = stubFetch(fakeResponse(200, { data: segments }));

    const wrapper = mount(SegmentBar, {
        props: { objectType, fields, activeSegmentId, ...extraProps },
        global: { stubs },
    });

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await flushPromises();

    return wrapper;
}

function findGroup(wrapper: VueWrapper, bucket: string) {
    return wrapper.find(`[data-segment-group="${bucket}"]`);
}

function entryIdsIn(
    wrapper: VueWrapper,
    bucket: string,
): (string | undefined)[] {
    const group = findGroup(wrapper, bucket);

    if (!group.exists()) {
        return [];
    }

    return group
        .findAll('[data-segment-entry]')
        .map((entry) => entry.attributes('data-segment-id'));
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('SegmentBar — grouped segment list (SC-2, toolbar grouping)', () => {
    it('renders default/system/own buckets with disjoint entries by precedence default > system > own', async () => {
        const defaultAndOwner = summary({
            id: 'seg-default',
            name: 'Alle offenen',
            is_default: true,
            is_owner: true,
        });
        const system = summary({
            id: 'seg-system',
            name: 'System-Segment',
            is_system: true,
        });
        const own = summary({
            id: 'seg-own',
            name: 'Mein Segment',
            is_owner: true,
        });

        const { wrapper } = await mountBar([defaultAndOwner, system, own]);

        const groupOf = (bucket: string) =>
            wrapper.find(`[data-segment-group="${bucket}"]`);

        expect(groupOf('default').exists()).toBe(true);
        expect(groupOf('system').exists()).toBe(true);
        expect(groupOf('own').exists()).toBe(true);

        const idsIn = (bucket: string) =>
            groupOf(bucket)
                .findAll('[data-segment-entry]')
                .map((e) => e.attributes('data-segment-id'));

        expect(idsIn('default')).toEqual(['seg-default']);
        expect(idsIn('system')).toEqual(['seg-system']);
        expect(idsIn('own')).toEqual(['seg-own']);

        const allIds = wrapper
            .findAll('[data-segment-entry]')
            .map((e) => e.attributes('data-segment-id'));
        expect(allIds).toHaveLength(3);
        expect(new Set(allIds).size).toBe(3);
    });

    it('labels its section for assistive technology without spending width on a visible caption (D1.4)', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

        expect(wrapper.get('section').attributes('aria-label')).toBe(
            'Segmente',
        );
        expect(wrapper.text()).not.toContain('Segmente');
    });

    it('gives the freed width to the segment picker', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

        expect(wrapper.get('[data-segment-picker]').classes()).toContain(
            'min-w-56',
        );
    });
});

describe('SegmentBar — default/system/own/shared four-bucket split (SC-12)', () => {
    function mixedPayload(): SegmentSummary[] {
        return [
            summary({
                id: 'seg-admin-default',
                name: 'Firmen-Standard',
                is_default: true,
            }),
            summary({
                id: 'seg-system',
                name: 'Recently updated',
                is_system: true,
            }),
            summary({
                id: 'seg-own',
                name: 'Mein Segment',
                is_owner: true,
            }),
            summary({
                id: 'seg-shared',
                name: 'Geteiltes Segment',
                is_owner: false,
                is_default: false,
                is_system: false,
            }),
        ];
    }

    it('renders four distinct groups default/system/own/shared, each with exactly its one entry', async () => {
        const { wrapper } = await mountBar(mixedPayload());

        for (const bucket of ['default', 'system', 'own', 'shared']) {
            expect(findGroup(wrapper, bucket).exists()).toBe(true);
        }

        expect(entryIdsIn(wrapper, 'default')).toEqual(['seg-admin-default']);
        expect(entryIdsIn(wrapper, 'system')).toEqual(['seg-system']);
        expect(entryIdsIn(wrapper, 'own')).toEqual(['seg-own']);
        expect(entryIdsIn(wrapper, 'shared')).toEqual(['seg-shared']);
    });

    it('places a shared segment (is_owner=false, non-default, non-system) in shared, never in own', async () => {
        const { wrapper } = await mountBar(mixedPayload());

        expect(entryIdsIn(wrapper, 'shared')).toContain('seg-shared');
        expect(entryIdsIn(wrapper, 'own')).not.toContain('seg-shared');
    });

    it('partitions every row disjointly across the four buckets with no duplicate ids', async () => {
        const payload = mixedPayload();
        const { wrapper } = await mountBar(payload);

        const allIds = wrapper
            .findAll('[data-segment-entry]')
            .map((e) => e.attributes('data-segment-id'));
        expect(allIds).toHaveLength(payload.length);
        expect(new Set(allIds).size).toBe(payload.length);

        const union = [
            ...entryIdsIn(wrapper, 'default'),
            ...entryIdsIn(wrapper, 'system'),
            ...entryIdsIn(wrapper, 'own'),
            ...entryIdsIn(wrapper, 'shared'),
        ];
        expect(union).toHaveLength(payload.length);
        expect(new Set(union).size).toBe(payload.length);
    });

    it('resolves a row that is both default and owner to default, not own (precedence tie-break)', async () => {
        const { wrapper } = await mountBar([
            summary({
                id: 'seg-default-owner',
                name: 'Standard & Eigen',
                is_default: true,
                is_owner: true,
            }),
        ]);

        expect(entryIdsIn(wrapper, 'default')).toEqual(['seg-default-owner']);
        expect(findGroup(wrapper, 'own').exists()).toBe(false);
    });

    it('emits no apply and does not throw when a system-descriptor row without a real id is clicked', async () => {
        const { wrapper } = await mountBar([
            summary({ id: '', name: 'Mine', is_system: true }),
        ]);

        const entry = wrapper.find(
            '[data-segment-group="system"] [data-segment-entry]',
        );
        expect(entry.exists()).toBe(true);

        await entry.trigger('click');

        expect(wrapper.emitted('apply')).toBeUndefined();
    });

    it('shows the empty state and renders no segment groups when the index returns nothing', async () => {
        const { wrapper } = await mountBar([]);

        expect(wrapper.find('[data-segment-empty]').exists()).toBe(true);
        expect(wrapper.findAll('[data-segment-group]')).toHaveLength(0);
        expect(wrapper.findAll('[data-segment-entry]')).toHaveLength(0);
    });
});

describe('SegmentBar — apply is emit-only (no grid/datasource access)', () => {
    it('emits exactly one apply event carrying the selected segment on entry click', async () => {
        const seg = summary({ id: 'seg-own', name: 'Mein Segment' });
        const { wrapper, fetchMock } = await mountBar([seg]);

        const callsAfterLoad = fetchMock.mock.calls.length;

        await wrapper.find('[data-segment-entry]').trigger('click');

        const applied = wrapper.emitted('apply');
        expect(applied).toHaveLength(1);
        expect(applied![0][0]).toEqual({ segment: seg });

        expect(fetchMock.mock.calls.length).toBe(callsAfterLoad);
    });
});

describe('SegmentBar — empty and error states', () => {
    it('shows an empty state when the index returns no segments', async () => {
        const { wrapper } = await mountBar([]);

        expect(wrapper.find('[data-segment-empty]').exists()).toBe(true);
        expect(wrapper.findAll('[data-segment-entry]')).toHaveLength(0);
    });

    it('surfaces a visible error when the index request rejects (no silent catch)', async () => {
        const fetchMock = vi.fn().mockRejectedValue(new Error('network down'));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        const error = wrapper.find('[data-segment-error]');
        expect(error.exists()).toBe(true);
        expect(error.text().trim().length).toBeGreaterThan(0);
    });

    it('surfaces a visible error when the index responds non-OK', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const fetchMock = stubFetch(fakeResponse(500, {}));

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        expect(wrapper.find('[data-segment-error]').exists()).toBe(true);
    });
});

async function mountDialog() {
    const wrapper = mount(SegmentSaveDialog, {
        props: { open: true, objectType, fields },
        global: { stubs },
    });
    await flushPromises();

    return wrapper;
}

describe('SegmentSaveDialog — embeds the FilterBuilder (SC-2)', () => {
    it('shows the embedded FilterBuilder', async () => {
        const wrapper = await mountDialog();

        expect(wrapper.findComponent(FilterBuilderStub).exists()).toBe(true);
    });

    it('offers no FilterBuilder when the object type has no filterable fields', async () => {
        const wrapper = mount(SegmentSaveDialog, {
            props: {
                open: true,
                objectType,
                fields: [
                    {
                        key: 'name',
                        field_type: 'text_short',
                        label: 'Name',
                        is_required: false,
                        is_filterable: false,
                    },
                ],
            },
            global: { stubs },
        });
        await flushPromises();

        expect(wrapper.findComponent(FilterBuilderStub).exists()).toBe(false);
    });
});

describe('SegmentSaveDialog — stale-tree contract', () => {
    it('blocks save until FilterBuilder has emitted a tree, then posts that exact tree', async () => {
        const saved: SegmentSummary = summary({
            id: 'seg-new',
            name: 'Neu',
        });
        const fetchMock = stubFetch(fakeResponse(201, { data: saved }));

        const wrapper = await mountDialog();

        await wrapper.find('[data-segment-name]').setValue('Neu');

        const saveButton = wrapper.find('[data-segment-save]');
        expect(saveButton.attributes('disabled')).toBeDefined();

        await saveButton.trigger('click');
        expect(fetchMock).not.toHaveBeenCalled();

        const tree: FilterGroupNode = {
            combinator: 'and',
            conditions: [{ field: 'name', operator: 'equals', value: 'Acme' }],
        };
        await wrapper
            .findComponent(FilterBuilderStub)
            .vm.$emit('update:modelValue', tree);
        await flushPromises();

        expect(
            wrapper.find('[data-segment-save]').attributes('disabled'),
        ).toBeUndefined();

        await wrapper.find('[data-segment-save]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const init = fetchMock.mock.calls[0][1] as RequestInit;
        const body = JSON.parse(init.body as string) as Record<string, unknown>;
        expect(body.filter_definition).toEqual(tree);

        const savedEvent = wrapper.emitted('saved');
        expect(savedEvent).toHaveLength(1);
        expect(savedEvent![0][0]).toEqual(saved);
    });
});

describe('SegmentSaveDialog — form reset on close (stale-tree guard)', () => {
    const tree: FilterGroupNode = {
        combinator: 'and',
        conditions: [{ field: 'name', operator: 'equals', value: 'Acme' }],
    };

    it('resets name and pending tree when the dialog is closed and reopened', async () => {
        const wrapper = await mountDialog();

        await wrapper.find('[data-segment-name]').setValue('Neu');
        await wrapper
            .findComponent(FilterBuilderStub)
            .vm.$emit('update:modelValue', tree);
        await flushPromises();
        expect(
            wrapper.find('[data-segment-save]').attributes('disabled'),
        ).toBeUndefined();

        const cancel = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Abbrechen'));
        expect(cancel).toBeDefined();
        await cancel!.trigger('click');
        await flushPromises();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(
            (wrapper.find('[data-segment-name]').element as HTMLInputElement)
                .value,
        ).toBe('');
        expect(
            wrapper.find('[data-segment-save]').attributes('disabled'),
        ).toBeDefined();
    });
});

describe('SegmentBar action row', () => {
    it('offers creating a segment as the first entry of the dropdown', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);
        const entries = wrapper.findAll(
            '[data-segment-create], [data-segment-entry]',
        );

        expect(entries[0].attributes('data-segment-create')).toBeDefined();
        expect(entries[0].text()).toContain('Neues Segment anlegen');
    });

    it('opens the save dialog from that entry', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);
        const dialog = wrapper.findComponent(SegmentSaveDialog);

        expect(dialog.props('open')).toBe(false);

        await wrapper.get('[data-segment-create]').trigger('click');

        expect(dialog.props('open')).toBe(true);
    });

    it('no longer keeps a separate create icon beside the dropdown', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

        expect(wrapper.find('[data-testid="segment-create"]').exists()).toBe(
            false,
        );
    });

    it('hides the clear action while no segment is active', async () => {
        const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

        expect(wrapper.find('[data-testid="segment-clear"]').exists()).toBe(
            false,
        );
    });

    it('renders the clear action as an icon button once a segment is active', async () => {
        const fetchMock = stubFetch(
            fakeResponse(200, { data: [summary({ id: 'seg-own' })] }),
        );

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields, activeSegmentId: 'seg-own' },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        const clear = wrapper.get('[data-testid="segment-clear"]');

        expect(clear.text().trim()).toBe('');
        expect(clear.attributes('aria-label')).toBe('Segment schließen');
        expect(clear.attributes('title')).toBe('Segment schließen');
    });

    it('emits clear when the clear action is used', async () => {
        const fetchMock = stubFetch(
            fakeResponse(200, { data: [summary({ id: 'seg-own' })] }),
        );

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields, activeSegmentId: 'seg-own' },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        await wrapper.get('[data-testid="segment-clear"]').trigger('click');

        expect(wrapper.emitted('clear')).toHaveLength(1);
    });

    it('keeps the selection and both actions on a single row', async () => {
        const fetchMock = stubFetch(
            fakeResponse(200, { data: [summary({ id: 'seg-own' })] }),
        );

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields, activeSegmentId: 'seg-own' },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        const row = wrapper.get('section[aria-label="Segmente"]');

        expect(row.classes()).toContain('flex');
        expect(row.classes()).toContain('items-center');
        expect(row.find('[data-testid="segment-clear"]').exists()).toBe(true);
    });

    it('hides the share action while no segment is active', async () => {
        const { wrapper } = await mountBar([
            summary({ id: 'seg-own', is_owner: true }),
        ]);

        expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(
            false,
        );
    });

    it('hides the share action for a segment the user does not own', async () => {
        const wrapper = await mountActiveBar(
            [summary({ id: 'seg-foreign', is_owner: false })],
            'seg-foreign',
        );

        expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(
            false,
        );
    });

    it('hides the share action where the caller opted out of it', async () => {
        const wrapper = await mountActiveBar(
            [summary({ id: 'seg-own', is_owner: true })],
            'seg-own',
            { allowShare: false },
        );

        expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(
            false,
        );
        expect(wrapper.findComponent(SegmentShareDialog).exists()).toBe(false);
    });

    it('offers the share action for an own active segment and loads its recipients on click', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (String(url).includes('/share-options')) {
                return Promise.resolve(
                    fakeResponse(200, {
                        options: {
                            user: [{ value: 'u1', label: 'Olivia Baumann' }],
                            team: [{ value: 't1', label: 'Vertrieb' }],
                            role: [{ value: 'r1', label: 'Administrator' }],
                        },
                    }),
                );
            }

            return Promise.resolve(
                fakeResponse(200, {
                    data: [summary({ id: 'seg-own', is_owner: true })],
                }),
            );
        });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(SegmentBar, {
            props: { objectType, fields, activeSegmentId: 'seg-own' },
            global: { stubs },
        });

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await flushPromises();

        const share = wrapper.get('[data-testid="segment-share"]');

        expect(share.attributes('aria-label')).toBe('Segment teilen');

        await share.trigger('click');
        await flushPromises();

        expect(
            fetchMock.mock.calls.some(([url]) =>
                String(url).includes('/share-options'),
            ),
        ).toBe(true);

        expect(wrapper.findComponent(SegmentShareDialog).exists()).toBe(true);
        expect(wrapper.findComponent(SegmentShareDialog).props('open')).toBe(
            true,
        );
    });
});
