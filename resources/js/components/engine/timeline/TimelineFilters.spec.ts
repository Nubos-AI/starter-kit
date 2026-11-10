import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TimelineFilters from '@/components/engine/timeline/TimelineFilters.vue';
import { comboboxStubs, selectAt, selectStubs } from '@/tests/selectStubs';
import type { TimelineFilterState } from '@/types/timeline';
import type { SelectOption } from '@/types/ui';

const emptyFilterState: TimelineFilterState = {
    occurredFrom: null,
    occurredTo: null,
    actorType: null,
    actorId: null,
};

const actorOptions: SelectOption[] = [
    {
        value: '01ACTOR000000000000000ADA',
        label: 'Ada',
        avatar: { name: 'Ada' },
    },
    {
        value: '01ACTOR000000000000000BOB',
        label: 'Bob',
        avatar: { name: 'Bob' },
    },
];

function mountFilters(
    filterState: TimelineFilterState = emptyFilterState,
    options: SelectOption[] = actorOptions,
) {
    return mount(TimelineFilters, {
        props: { filterState, actorOptions: options },
        global: { stubs: { ...selectStubs, ...comboboxStubs } },
    });
}

function lastFilterState(
    wrapper: ReturnType<typeof mountFilters>,
): TimelineFilterState {
    const events = wrapper.emitted('update:filterState') ?? [];

    return events[events.length - 1][0] as TimelineFilterState;
}

describe('TimelineFilters — the date range', () => {
    it.each([
        ['from', 'occurredFrom', '2026-03-01'],
        ['to', 'occurredTo', '2026-03-31'],
    ] as const)(
        'reports the bound the reader typed into the %s field',
        async (field, key, value) => {
            const wrapper = mountFilters();

            await wrapper.get(`#timeline-filter-${field}`).setValue(value);

            expect(lastFilterState(wrapper)).toEqual({
                ...emptyFilterState,
                [key]: value,
            });
        },
    );

    it.each([
        ['from', 'occurredFrom', '2026-03-01'],
        ['to', 'occurredTo', '2026-03-31'],
    ] as const)(
        'drops the bound again when the %s field is cleared',
        async (field, key, value) => {
            const wrapper = mountFilters({
                ...emptyFilterState,
                [key]: value,
            });

            await wrapper.get(`#timeline-filter-${field}`).setValue('');

            expect(lastFilterState(wrapper)[key]).toBeNull();
        },
    );

    it('keeps the other bound untouched while one of them changes', async () => {
        const wrapper = mountFilters({
            occurredFrom: '2026-03-01',
            occurredTo: null,
            actorType: 'automation',
            actorId: null,
        });

        await wrapper.get('#timeline-filter-to').setValue('2026-03-31');

        expect(lastFilterState(wrapper)).toEqual({
            occurredFrom: '2026-03-01',
            occurredTo: '2026-03-31',
            actorType: 'automation',
            actorId: null,
        });
    });
});

describe('TimelineFilters — the actor type', () => {
    it('offers every known actor type next to the all placeholder', () => {
        const wrapper = mountFilters();

        expect(
            selectAt(wrapper)
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual(['__all__', 'user', 'automation', 'system']);
    });

    it('reports the actor type the reader picked', async () => {
        const wrapper = mountFilters();

        await selectAt(wrapper).setValue('automation');

        expect(lastFilterState(wrapper).actorType).toBe('automation');
    });

    it('drops the actor type when the reader picks the all placeholder again', async () => {
        const wrapper = mountFilters({
            ...emptyFilterState,
            actorType: 'automation',
        });

        await selectAt(wrapper).setValue('__all__');

        expect(lastFilterState(wrapper).actorType).toBeNull();
    });

    it('reports no actor type for a value that is not a known one', async () => {
        const wrapper = mountFilters({
            ...emptyFilterState,
            actorType: 'user',
        });

        await selectAt(wrapper).setValue('webinar');

        expect(lastFilterState(wrapper).actorType).toBeNull();
    });
});

describe('TimelineFilters — the concrete actor', () => {
    it('offers a concrete actor next to the actor type', () => {
        const wrapper = mountFilters();

        expect(
            wrapper
                .get('#timeline-filter-actor')
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual([
            '__all__',
            '01ACTOR000000000000000ADA',
            '01ACTOR000000000000000BOB',
        ]);
    });

    it('reports the actor id the reader picked next to its actor type', async () => {
        const wrapper = mountFilters({
            ...emptyFilterState,
            actorType: 'user',
        });

        await wrapper
            .get('#timeline-filter-actor')
            .setValue('01ACTOR000000000000000ADA');

        expect(lastFilterState(wrapper)).toEqual({
            occurredFrom: null,
            occurredTo: null,
            actorType: 'user',
            actorId: '01ACTOR000000000000000ADA',
        });
    });

    it('drops the actor again when the reader picks the all placeholder', async () => {
        const wrapper = mountFilters({
            ...emptyFilterState,
            actorId: '01ACTOR000000000000000ADA',
        });

        await wrapper.get('#timeline-filter-actor').setValue('__all__');

        expect(lastFilterState(wrapper).actorId).toBeNull();
    });

    it('leaves the actor picker out while the strand shows no actor yet', () => {
        expect(
            mountFilters(emptyFilterState, [])
                .find('#timeline-filter-actor')
                .exists(),
        ).toBe(false);
    });
});
