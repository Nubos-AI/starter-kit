import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import RecordRelations from '@/components/engine/records/RecordRelations.vue';
import { comboboxStubs, multiSelectStubs } from '@/tests/selectStubs';
import type { RelationGroup } from '@/types/relations';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const {
    state,
    loadMock,
    linkMock,
    unlinkMock,
    loadMoreOptionsMock,
    searchOptionsMock,
} = vi.hoisted(() => ({
    state: { groups: [] as RelationGroup[] },
    loadMock: vi.fn(),
    linkMock: vi.fn(),
    unlinkMock: vi.fn(),
    loadMoreOptionsMock: vi.fn(),
    searchOptionsMock: vi.fn(),
}));

vi.mock('@/composables/useRecordRelations', () => ({
    useRecordRelations: () => ({
        groups: ref(state.groups),
        loading: ref(false),
        error: ref(null),
        load: loadMock,
        link: linkMock,
        unlink: unlinkMock,
        loadMoreOptions: loadMoreOptionsMock,
        searchOptions: searchOptionsMock,
    }),
}));

function hierarchyGroups(): RelationGroup[] {
    return [
        {
            relationshipTypeId: 'rt-1',
            direction: 'incoming',
            isHierarchy: true,
            objectTypeName: 'Deals',
            roleLabel: 'Deals child',
            acceptsOne: true,
            canEdit: true,
            candidates: [{ id: 'rec-other', label: 'DL-0000000009 · Andere' }],
            candidatesTruncated: false,
            entriesTotal: 1,
            entriesTruncated: false,
            entries: [
                {
                    linkId: 'lnk-1',
                    recordId: 'rec-parent',
                    recordNumber: 'DL-0000000000',
                    label: 'DL-0000000000 · Großkunde',
                },
            ],
        },
        {
            relationshipTypeId: 'rt-1',
            direction: 'outgoing',
            isHierarchy: true,
            objectTypeName: 'Deals',
            roleLabel: 'Deals parent',
            acceptsOne: false,
            canEdit: true,
            candidates: [{ id: 'rec-free', label: 'DL-0000000007 · Frei' }],
            candidatesTruncated: false,
            entriesTotal: 1,
            entriesTruncated: false,
            entries: [
                {
                    linkId: 'lnk-2',
                    recordId: 'rec-child',
                    recordNumber: 'DL-0000000001',
                    label: 'DL-0000000001 · Kind',
                },
            ],
        },
    ];
}

function mountRelations(readonly = false) {
    return mount(RecordRelations, {
        props: { recordId: 'rec-1', readonly },
        global: { stubs: { ...comboboxStubs, ...multiSelectStubs } },
    });
}

function combobox(wrapper: ReturnType<typeof mountRelations>) {
    return wrapper.get<HTMLSelectElement>('select.ui-combobox');
}

function multiSelect(wrapper: ReturnType<typeof mountRelations>) {
    return wrapper.get<HTMLSelectElement>('select.ui-multi-select');
}

beforeEach(() => {
    state.groups = hierarchyGroups();
    loadMock.mockReset();
    linkMock.mockReset().mockResolvedValue(true);
    unlinkMock.mockReset().mockResolvedValue(true);
    loadMoreOptionsMock.mockReset().mockResolvedValue(undefined);
    searchOptionsMock.mockReset().mockResolvedValue(undefined);
});

describe('RecordRelations', () => {
    it('collapses and expands like the data card does', async () => {
        const wrapper = mountRelations();

        expect(wrapper.find('[data-relation-group]').exists()).toBe(true);

        await wrapper.get('[data-record-relations-toggle]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-relation-group]').exists()).toBe(false);

        await wrapper.get('[data-record-relations-toggle]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-relation-group]').exists()).toBe(true);
    });

    it('loads the relations of the record it is given', () => {
        mountRelations();

        expect(loadMock).toHaveBeenCalledWith('rec-1');
    });

    it('names the counterpart object type and puts the role behind it', () => {
        const wrapper = mountRelations();
        const groups = wrapper.findAll('[data-relation-group]');

        expect(groups).toHaveLength(2);
        expect(groups[0].get('[data-relation-name]').text()).toBe('Deals');
        expect(groups[0].get('[data-relation-role]').text()).toBe(
            'übergeordnet',
        );
        expect(groups[1].get('[data-relation-role]').text()).toBe(
            'untergeordnet',
        );
    });

    it('offers a single searchable dropdown where only one relation fits', () => {
        const wrapper = mountRelations();
        const select = combobox(wrapper);

        expect(select.element.value).toBe('rec-parent');
        expect(select.findAll('option').map((option) => option.text())).toEqual(
            [
                'Keine Verknüpfung',
                'DL-0000000000 · Großkunde',
                'DL-0000000009 · Andere',
            ],
        );
    });

    it('never offers an option without a value, which the combobox refuses', () => {
        const wrapper = mountRelations();

        expect(
            combobox(wrapper)
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).not.toContain('');
    });

    it('shows the empty option as chosen while nothing is linked', () => {
        state.groups = hierarchyGroups().map((group) => ({
            ...group,
            entries: [],
            entriesTotal: 0,
        }));

        const wrapper = mountRelations();

        expect(combobox(wrapper).element.value).toBe('none');
    });

    it('offers a multi select where the relation takes many', () => {
        const wrapper = mountRelations();
        const select = multiSelect(wrapper);

        expect(
            select
                .findAll('option')
                .filter((option) => option.attributes('selected') !== undefined)
                .map((option) => option.attributes('value')),
        ).toEqual(['rec-child']);
        expect(
            select
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual(['rec-child', 'rec-free']);
    });

    it('links the newly picked record of a single relation', async () => {
        const wrapper = mountRelations();

        await combobox(wrapper).setValue('rec-other');
        await flushPromises();

        expect(linkMock).toHaveBeenCalledWith(
            'rec-1',
            'rt-1',
            'incoming',
            'rec-other',
        );
        expect(loadMock).toHaveBeenCalledTimes(2);
    });

    it('unlinks a single relation once it is cleared', async () => {
        const wrapper = mountRelations();

        await combobox(wrapper).setValue('none');
        await flushPromises();

        expect(unlinkMock).toHaveBeenCalledWith('rec-1', 'lnk-1');
        expect(linkMock).not.toHaveBeenCalled();
    });

    it('sums several relations up instead of listing them as badges', () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? {
                      ...group,
                      entries: [
                          ...group.entries,
                          {
                              linkId: 'lnk-3',
                              recordId: 'rec-child-2',
                              recordNumber: 'DL-0000000003',
                              label: 'DL-0000000003 · Zweites Kind',
                          },
                      ],
                      entriesTotal: 2,
                  }
                : group,
        );

        const wrapper = mountRelations();
        const trigger = wrapper.get('[data-relation-trigger]');

        expect(trigger.text()).toBe('2 untergeordnete');
        expect(trigger.text()).not.toContain('DL-0000000001');
    });

    it('names the single relation of a multi select in full', () => {
        const wrapper = mountRelations();

        expect(wrapper.get('[data-relation-trigger]').text()).toBe(
            'DL-0000000001 · Kind',
        );
    });

    it('says when a multi select holds no relation at all', () => {
        state.groups = hierarchyGroups().map((group) => ({
            ...group,
            entries: [],
            entriesTotal: 0,
        }));

        const wrapper = mountRelations();

        expect(wrapper.get('[data-relation-trigger]').text()).toBe(
            'Keine Verknüpfung',
        );
    });

    it('links what the multi select adds and unlinks what it drops', async () => {
        const wrapper = mountRelations();
        const select = multiSelect(wrapper);

        await select.setValue(['rec-free']);
        await flushPromises();

        expect(linkMock).toHaveBeenCalledWith(
            'rec-1',
            'rt-1',
            'outgoing',
            'rec-free',
        );
        expect(unlinkMock).toHaveBeenCalledWith('rec-1', 'lnk-2');
    });

    it('locks every dropdown while the record is read-only', () => {
        const wrapper = mountRelations(true);

        expect(combobox(wrapper).attributes('disabled')).toBeDefined();
        expect(multiSelect(wrapper).attributes('disabled')).toBeDefined();
    });

    it('locks the dropdown of a relation the user may not change', () => {
        state.groups = hierarchyGroups().map((group) => ({
            ...group,
            canEdit: false,
        }));

        const wrapper = mountRelations();

        expect(combobox(wrapper).attributes('disabled')).toBeDefined();
    });

    it('counts the summary from the exact total instead of the loaded entries', () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? { ...group, entriesTotal: 1200, entriesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        expect(wrapper.get('[data-relation-trigger]').text()).toContain(
            '1.200 untergeordnete',
        );
    });

    it('unlinks only what was deselected in a capped group, never the entries it never loaded', async () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? { ...group, entriesTotal: 1200, entriesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();
        const select = wrapper.get('select.ui-multi-select');

        (select.element as HTMLSelectElement).querySelectorAll(
            'option',
        )[0].selected = false;

        await select.trigger('change');
        await flushPromises();

        expect(unlinkMock).toHaveBeenCalledTimes(1);
    });

    it('gives a capped group the same multi select as any other, not a list of its own', () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? { ...group, entriesTotal: 1200, entriesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        expect(wrapper.find('[data-relation-summary]').exists()).toBe(false);
        expect(wrapper.get('[data-relation-trigger]').text()).toContain(
            '1.200 untergeordnete',
        );
    });

    it('lets the server answer the search of a capped group', async () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? { ...group, entriesTotal: 1200, entriesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        await wrapper.get('input.ui-multi-select-search').setValue('Hafen');
        await new Promise((resolve) => setTimeout(resolve, 250));

        expect(searchOptionsMock).toHaveBeenCalledWith(
            'rec-1',
            expect.objectContaining({ entriesTruncated: true }),
            'Hafen',
        );
    });

    it('loads the next block of a capped group when its list asks for more', async () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? { ...group, entriesTotal: 1200, entriesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        await wrapper.get('select.ui-multi-select').trigger('scroll');
        await flushPromises();

        expect(loadMoreOptionsMock).toHaveBeenCalledTimes(1);
    });

    it('shows the candidates the server narrowed instead of filtering them again', () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'outgoing'
                ? {
                      ...group,
                      entriesTotal: 1200,
                      entriesTruncated: true,
                      optionsSearch: 'Hafen',
                      candidates: [
                          { id: 'cand-1', label: 'Hafenprojekt' },
                          { id: 'cand-2', label: 'Hafenstraße' },
                      ],
                  }
                : group,
        );

        const labels = mountRelations()
            .get('select.ui-multi-select')
            .findAll('option')
            .map((option) => option.text());

        expect(labels).toContain('Hafenprojekt');
        expect(labels).toContain('Hafenstraße');
    });

    it('lets the server answer the search of a single select whose candidates are capped', async () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'incoming'
                ? { ...group, candidatesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        await wrapper.get('input.ui-combobox-search').setValue('Hafen');
        await new Promise((resolve) => setTimeout(resolve, 250));

        expect(searchOptionsMock).toHaveBeenCalledWith(
            'rec-1',
            expect.objectContaining({ candidatesTruncated: true }),
            'Hafen',
        );
    });

    it('loads the next block of candidates when the single select asks for more', async () => {
        state.groups = hierarchyGroups().map((group) =>
            group.direction === 'incoming'
                ? { ...group, candidatesTruncated: true }
                : group,
        );

        const wrapper = mountRelations();

        await wrapper.get('select.ui-combobox').trigger('scroll');
        await flushPromises();

        expect(loadMoreOptionsMock).toHaveBeenCalledTimes(1);
    });

    it('never hands the search of a group that fits to the server', () => {
        const wrapper = mountRelations();

        expect(wrapper.find('input.ui-multi-select-search').exists()).toBe(
            false,
        );
    });

    it('keeps the multi select of a group that fits', () => {
        expect(mountRelations().find('select.ui-multi-select').exists()).toBe(
            true,
        );
        expect(mountRelations().find('[data-relation-summary]').exists()).toBe(
            false,
        );
    });

    it('hands the search of a group with capped candidates to the server', async () => {
        state.groups = hierarchyGroups().map((group) => ({
            ...group,
            candidatesTruncated: true,
        }));

        const wrapper = mountRelations();

        await wrapper.get('input.ui-multi-select-search').setValue('Hafen');
        await new Promise((resolve) => setTimeout(resolve, 250));

        expect(searchOptionsMock).toHaveBeenCalledTimes(1);
    });
});
