import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RecordTimeline from '@/components/engine/timeline/RecordTimeline.vue';
import TimelineFilters from '@/components/engine/timeline/TimelineFilters.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { TimelineEntry } from '@/types/timeline';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

interface TimelineVisitOptions {
    only?: string[];
    reset?: string[];
    preserveState?: boolean;
    preserveScroll?: boolean;
    preserveUrl?: boolean;
    replace?: boolean;
    onSuccess?: (visit: { props: Record<string, unknown> }) => void;
    onFinish?: () => void;
}

interface RecordedVisit {
    url: string;
    query: Record<string, unknown>;
    options: TimelineVisitOptions;
}

type ServerAnswer =
    | { shape: 'entries'; data: TimelineEntry[]; nextPage: string | null }
    | { shape: 'without-prop' }
    | { shape: 'without-data' };

interface FakePage {
    url: string;
    props: { timelineEntries?: { data?: TimelineEntry[] } };
    scrollProps: { timelineEntries?: { nextPage: string | null } };
}

const recordId = '01RECORD00000000000000000A';

const inertia = vi.hoisted(() => ({
    visits: [] as RecordedVisit[],
    answers: [] as ServerAnswer[],
    page: undefined as FakePage | undefined,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    inertia.page = reactive<FakePage>({
        url: '/nubos/records/01RECORD00000000000000000A',
        props: {},
        scrollProps: {},
    });

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => inertia.page,
        router: {
            on: vi.fn(() => vi.fn()),
            visit: vi.fn(),
            reload: vi.fn(),
            get: (
                url: string,
                query: Record<string, unknown>,
                options: TimelineVisitOptions,
            ): void => {
                inertia.visits.push({ url, query, options });

                const answer = inertia.answers.shift();
                const page = inertia.page;

                if (answer === undefined || page === undefined) {
                    return;
                }

                if (answer.shape === 'without-prop') {
                    page.props = {};
                    page.scrollProps = {};
                } else if (answer.shape === 'without-data') {
                    page.props = { timelineEntries: {} };
                    page.scrollProps = { timelineEntries: { nextPage: null } };
                } else {
                    const kept =
                        options.reset?.includes('timelineEntries') === true
                            ? []
                            : (page.props.timelineEntries?.data ?? []);

                    page.props = {
                        timelineEntries: { data: [...kept, ...answer.data] },
                    };
                    page.scrollProps = {
                        timelineEntries: { nextPage: answer.nextPage },
                    };
                }

                options.onSuccess?.({ props: page.props });
                queueMicrotask(() => options.onFinish?.());
            },
        },
    };
});

const base: Omit<TimelineEntry, 'sourceKey' | 'payload'> = {
    id: 'tl-a',
    sourceId: null,
    actorId: null,
    actorType: 'user',
    actorLabel: 'Mara Kessler',
    channel: 'web',
    occurredAt: '2026-03-05T09:00:00.000000Z',
    canOpenAutomation: false,
};

function stageEntry(id: string): TimelineEntry {
    return {
        ...base,
        id,
        sourceKey: 'stage_change',
        payload: {
            old_stage: {
                id: '01STATUS000000000000000AAA',
                key: 'offen',
                labels: { de: 'Offen' },
                color: null,
            },
            new_stage: {
                id: '01STATUS000000000000000BBB',
                key: 'gewonnen',
                labels: { de: 'Gewonnen' },
                color: null,
            },
        },
    };
}

function noteEntry(id: string): TimelineEntry {
    return {
        ...base,
        id,
        sourceKey: 'note',
        sourceId: '01NOTE00000000000000000001',
        payload: {
            authorId: null,
            authorName: 'Mara Kessler',
            body: 'Kunde hat zugesagt.',
        },
    };
}

function entriesAnswer(
    data: TimelineEntry[],
    nextPage: string | null,
): ServerAnswer {
    return { shape: 'entries', data, nextPage };
}

function queue(...answers: ServerAnswer[]): void {
    inertia.answers.push(...answers);
}

function mountTimeline() {
    return mount(RecordTimeline, {
        props: { recordId, fields: [] },
        global: { stubs: { ...selectStubs } },
    });
}

function lastVisit(): RecordedVisit {
    return inertia.visits[inertia.visits.length - 1];
}

function rowIds(wrapper: VueWrapper): string[] {
    return wrapper
        .findAll('[data-timeline-entry]')
        .map((row) => row.attributes('data-timeline-entry-id') ?? '');
}

function switchTab(wrapper: VueWrapper, tab: string): Promise<void> {
    return wrapper
        .get(`[data-timeline-tab="${tab}"]`)
        .trigger('mousedown', { button: 0 });
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.answers.length = 0;

    if (inertia.page !== undefined) {
        inertia.page.url = `/nubos/records/${recordId}`;
        inertia.page.props = {};
        inertia.page.scrollProps = {};
    }
});

describe('RecordTimeline — the first page loads with the record page', () => {
    it('asks the timeline endpoint for the timelineEntries prop alone on mount', () => {
        mountTimeline();

        expect(inertia.visits).toHaveLength(1);
        expect(lastVisit().url).toBe(`/nubos/records/${recordId}/timeline`);
        expect(lastVisit().options.only).toEqual(['timelineEntries']);
        expect(lastVisit().options.preserveState).toBe(true);
        expect(lastVisit().options.preserveScroll).toBe(true);
        expect(lastVisit().options.preserveUrl).toBe(true);
        expect(lastVisit().query).not.toHaveProperty('sources');
        expect(lastVisit().query).not.toHaveProperty('timelineCursor');
    });

    it('shows a pulsing skeleton and no empty state while the call is open', () => {
        const wrapper = mountTimeline();

        expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(false);
    });

    it('renders one row per delivered entry', async () => {
        queue(entriesAnswer([stageEntry('tl-a'), noteEntry('tl-b')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(rowIds(wrapper)).toEqual(['tl-a', 'tl-b']);
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(false);
    });
});

describe('RecordTimeline — loading more appends instead of duplicating', () => {
    it('shows exactly four distinct rows after the second page', async () => {
        queue(
            entriesAnswer(
                [stageEntry('tl-a'), stageEntry('tl-b')],
                'fp123456789a.eyJpZCI6IjAxIn0',
            ),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-c'), noteEntry('tl-d')], null));

        await wrapper.get('[data-timeline-load-more]').trigger('click');
        await flushPromises();

        const ids = rowIds(wrapper);

        expect(ids).toHaveLength(4);
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids).toEqual(['tl-a', 'tl-b', 'tl-c', 'tl-d']);
    });

    it('sends the opaque cursor back byte for byte and keeps the merge', async () => {
        queue(
            entriesAnswer([stageEntry('tl-a')], 'fp123456789a.eyJpZCI6IjAxIn0'),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-b')], null));

        await wrapper.get('[data-timeline-load-more]').trigger('click');
        await flushPromises();

        expect(inertia.visits).toHaveLength(2);
        expect(lastVisit().query.timelineCursor).toBe(
            'fp123456789a.eyJpZCI6IjAxIn0',
        );
        expect(lastVisit().options.reset ?? []).not.toContain(
            'timelineEntries',
        );
    });

    it('hides the load more button once the server stops handing out a cursor', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(wrapper.find('[data-timeline-load-more]').exists()).toBe(false);
    });
});

describe('RecordTimeline — filtering happens on the server', () => {
    it('mirrors the core tabs above the timeline', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(
            wrapper
                .findAll('[data-timeline-tab]')
                .map((tab) => tab.attributes('data-timeline-tab')),
        ).toEqual(['all', 'note', 'activity', 'reminder', 'file', 'change']);
    });

    it('asks for every change source on the changes tab', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([stageEntry('tl-b')], null));
        await switchTab(wrapper, 'change');
        await flushPromises();

        expect(lastVisit().query.sources).toEqual([
            'field_change',
            'stage_change',
            'relation',
            'merge',
        ]);
    });

    it('loads activities through the activity source filter', async () => {
        const wrapper = mountTimeline();
        await flushPromises();
        queue(entriesAnswer([], null));
        await switchTab(wrapper, 'activity');
        expect(lastVisit().query.sources).toEqual(['activity']);
        expect(wrapper.find('[data-timeline-activity-hint]').exists()).toBe(
            false,
        );
    });

    it('sends one source key and drops the cursor on a tab switch', async () => {
        queue(
            entriesAnswer([stageEntry('tl-a')], 'fp123456789a.eyJpZCI6IjAxIn0'),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-note')], null));

        await switchTab(wrapper, 'note');
        await flushPromises();

        expect(lastVisit().query.sources).toEqual(['note']);
        expect(lastVisit().options.reset).toContain('timelineEntries');
        expect(lastVisit().query).not.toHaveProperty('timelineCursor');
        expect(rowIds(wrapper)).toEqual(['tl-note']);
        expect(
            wrapper.get('[data-timeline-tab="note"]').attributes('data-state'),
        ).toBe('active');
    });

    it('sends no source key at all on the all tab', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-note')], null));
        await switchTab(wrapper, 'note');
        await flushPromises();

        queue(entriesAnswer([stageEntry('tl-a')], null));
        await switchTab(wrapper, 'all');
        await flushPromises();

        expect(lastVisit().query).not.toHaveProperty('sources');
        expect(lastVisit().options.reset).toContain('timelineEntries');
    });

    it('reloads from the first page when the range filter changes', async () => {
        queue(
            entriesAnswer([stageEntry('tl-a')], 'fp123456789a.eyJpZCI6IjAxIn0'),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-b')], null));

        wrapper.findComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: '2026-03-01',
            occurredTo: null,
            actorType: null,
        });
        await flushPromises();

        expect(lastVisit().query.occurredFrom).toBe('2026-03-01');
        expect(lastVisit().options.reset).toContain('timelineEntries');
        expect(lastVisit().query).not.toHaveProperty('timelineCursor');
        expect(rowIds(wrapper)).toEqual(['tl-b']);
    });

    it('carries the actor type and the upper date bound into the visit query', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([noteEntry('tl-b')], null));

        wrapper.findComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: null,
            occurredTo: '2026-03-31',
            actorType: 'automation',
        });
        await flushPromises();

        expect(lastVisit().query.actorType).toBe('automation');
        expect(lastVisit().query.occurredTo).toBe('2026-03-31');
        expect(lastVisit().query).not.toHaveProperty('occurredFrom');
    });
});

describe('RecordTimeline — an empty timeline explains itself', () => {
    it('offers an explanation and no reset button without a filter', async () => {
        queue(entriesAnswer([], null));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(true);
        expect(wrapper.get('[data-timeline-empty]').text()).toContain(
            'noch keine',
        );
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(false);
        expect(wrapper.find('[data-timeline-reset-filters]').exists()).toBe(
            false,
        );
    });

    it('offers a filter reset when a filter emptied the timeline', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        queue(entriesAnswer([], null));

        wrapper.findComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: '2026-03-01',
            occurredTo: null,
            actorType: null,
        });
        await flushPromises();

        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-reset-filters]').exists()).toBe(
            true,
        );

        queue(entriesAnswer([stageEntry('tl-a')], null));

        await wrapper.get('[data-timeline-reset-filters]').trigger('click');
        await flushPromises();

        expect(lastVisit().query).not.toHaveProperty('occurredFrom');
        expect(rowIds(wrapper)).toEqual(['tl-a']);
    });
});

describe('RecordTimeline — a source key the frontend does not know', () => {
    it('renders the row neutrally and keeps the core tab strip', async () => {
        queue(
            entriesAnswer(
                [{ ...base, id: 'tl-x', sourceKey: 'webinar', payload: {} }],
                null,
            ),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        expect(rowIds(wrapper)).toEqual(['tl-x']);
        expect(
            wrapper
                .get('[data-timeline-entry]')
                .attributes('data-timeline-source'),
        ).toBe('webinar');
        expect(wrapper.findAll('[data-timeline-tab]')).toHaveLength(6);
    });
});

describe('RecordTimeline — a page emptied by redaction is followed up', () => {
    it('follows the cursor once when the delivered page arrived empty', async () => {
        queue(
            entriesAnswer([], 'fp123456789a.page1'),
            entriesAnswer([stageEntry('tl-a')], null),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        expect(inertia.visits).toHaveLength(2);
        expect(inertia.visits[1].query.timelineCursor).toBe(
            'fp123456789a.page1',
        );
        expect(rowIds(wrapper)).toEqual(['tl-a']);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(false);
    });

    it('keeps the skeleton up while an automatic follow-up is still open', async () => {
        queue(entriesAnswer([], 'fp123456789a.page1'));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(inertia.visits).toHaveLength(2);
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(false);
    });

    it('stops after three automatic follow-ups and hands the decision back', async () => {
        queue(
            entriesAnswer([], 'fp123456789a.page1'),
            entriesAnswer([], 'fp123456789a.page2'),
            entriesAnswer([], 'fp123456789a.page3'),
            entriesAnswer([], 'fp123456789a.page4'),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        expect(inertia.visits).toHaveLength(4);
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(false);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-load-more]').exists()).toBe(true);
    });
});

describe('RecordTimeline — a response that carries nothing usable', () => {
    it('stays on the empty state when the very first response carries no timelineEntries prop', async () => {
        queue({ shape: 'without-prop' });

        const wrapper = mountTimeline();
        await flushPromises();

        expect(inertia.visits).toHaveLength(1);
        expect(rowIds(wrapper)).toEqual([]);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(true);
    });

    it('survives a timelineEntries prop without a data array', async () => {
        queue({ shape: 'without-data' });

        const wrapper = mountTimeline();
        await flushPromises();

        expect(inertia.visits).toHaveLength(1);
        expect(rowIds(wrapper)).toEqual([]);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(true);
    });
});

describe('RecordTimeline — a full page reload drops the timeline payload', () => {
    it('loads the timeline again instead of falling back to the empty state', async () => {
        queue(entriesAnswer([stageEntry('tl-a')], null));

        const wrapper = mountTimeline();
        await flushPromises();

        expect(rowIds(wrapper)).toEqual(['tl-a']);

        if (inertia.page !== undefined) {
            inertia.page.props = {};
            inertia.page.scrollProps = {};
        }

        await flushPromises();

        expect(inertia.visits).toHaveLength(2);
        expect(lastVisit().options.reset).toContain('timelineEntries');
        expect(wrapper.find('[data-timeline-skeleton]').exists()).toBe(true);
        expect(wrapper.find('[data-timeline-empty]').exists()).toBe(false);
    });
});

describe('RecordTimeline — the strand only reads', () => {
    it('carries no note composer any more — writing lives in the detail tabs', () => {
        const wrapper = mount(RecordTimeline, {
            props: { recordId, fields: [] },
            global: { stubs: { ...selectStubs } },
        });

        expect(wrapper.find('[data-record-note-composer]').exists()).toBe(
            false,
        );
    });

    it('lets the page reload the strand from the first page', async () => {
        const wrapper = mount(RecordTimeline, {
            props: { recordId, fields: [] },
            global: { stubs: { ...selectStubs } },
        });
        await flushPromises();
        const before = inertia.visits.length;

        (wrapper.vm as unknown as { reload: () => void }).reload();
        await flushPromises();

        expect(inertia.visits.length).toBe(before + 1);
        expect(lastVisit().options.reset).toEqual(['timelineEntries']);
        expect(lastVisit().query).not.toHaveProperty('timelineCursor');
    });
});

describe('RecordTimeline — filtering down to one person', () => {
    it('offers exactly the actors the loaded strand shows, without duplicates', async () => {
        queue(
            entriesAnswer(
                [
                    {
                        ...noteEntry('tl-a'),
                        actorId: 'act-ada',
                        actorLabel: 'Ada',
                    },
                    {
                        ...noteEntry('tl-b'),
                        actorId: 'act-bob',
                        actorLabel: 'Bob',
                    },
                    {
                        ...noteEntry('tl-c'),
                        actorId: 'act-ada',
                        actorLabel: 'Ada',
                    },
                    { ...noteEntry('tl-d'), actorId: null, actorLabel: null },
                ],
                null,
            ),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        expect(
            wrapper.getComponent(TimelineFilters).props('actorOptions'),
        ).toEqual([
            { value: 'act-ada', label: 'Ada', avatar: { name: 'Ada' } },
            { value: 'act-bob', label: 'Bob', avatar: { name: 'Bob' } },
        ]);
    });

    it('carries the concrete actor id into the visit query', async () => {
        queue(entriesAnswer([], null), entriesAnswer([], null));

        const wrapper = mountTimeline();
        await flushPromises();

        wrapper.getComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: null,
            occurredTo: null,
            actorType: 'user',
            actorId: 'act-ada',
        });
        await flushPromises();

        expect(lastVisit().query.actorId).toBe('act-ada');
        expect(lastVisit().query.actorType).toBe('user');
    });

    it('never sends an actor id without the actor type the server demands', async () => {
        queue(entriesAnswer([], null), entriesAnswer([], null));

        const wrapper = mountTimeline();
        await flushPromises();

        wrapper.getComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: null,
            occurredTo: null,
            actorType: null,
            actorId: 'act-ada',
        });
        await flushPromises();

        expect(lastVisit().query).not.toHaveProperty('actorId');
    });

    it('drops the actor id from the query once it is cleared again', async () => {
        queue(
            entriesAnswer([], null),
            entriesAnswer([], null),
            entriesAnswer([], null),
        );

        const wrapper = mountTimeline();
        await flushPromises();

        wrapper.getComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: null,
            occurredTo: null,
            actorType: 'user',
            actorId: 'act-ada',
        });
        await flushPromises();

        wrapper.getComponent(TimelineFilters).vm.$emit('update:filterState', {
            occurredFrom: null,
            occurredTo: null,
            actorType: 'user',
            actorId: null,
        });
        await flushPromises();

        expect(lastVisit().query).not.toHaveProperty('actorId');
        expect(lastVisit().query.actorType).toBe('user');
    });
});
