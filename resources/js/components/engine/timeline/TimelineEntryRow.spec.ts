import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TimelineEntryRow from '@/components/engine/timeline/TimelineEntryRow.vue';
import type { FieldDefinition } from '@/types/fields';
import type { TimelineEntry } from '@/types/timeline';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), get: vi.fn() },
    useHttp: () => ({ processing: false, delete: vi.fn() }),
    usePage: () => ({
        url: '/nubos/records/01RECORD00000000000000000A',
        props: { auth: { user: null, can: {}, authority: null } },
        scrollProps: {},
    }),
}));

const recordId = '01RECORD00000000000000000A';

const base: Omit<TimelineEntry, 'sourceKey' | 'payload'> = {
    id: 'tl-1',
    sourceId: null,
    actorId: null,
    actorType: 'user',
    actorLabel: 'Mara Kessler',
    channel: 'web',
    occurredAt: '2026-03-05T09:00:00.000000Z',
    canOpenAutomation: false,
};

function definition(overrides: Partial<FieldDefinition> = {}): FieldDefinition {
    return {
        key: 'titel',
        field_type: 'text_short',
        label: 'Titel',
        is_required: false,
        ...overrides,
    };
}

const titleField = definition();

function activityEntry(links: unknown): TimelineEntry {
    return {
        id: 'entry-1',
        sourceKey: 'activity',
        sourceId: 'activity-1',
        actorId: null,
        actorType: null,
        actorLabel: null,
        channel: null,
        occurredAt: '2026-10-01T09:00:00Z',
        canOpenAutomation: false,
        payload: {
            subject: 'Angebot besprochen',
            typeName: 'Anruf',
            assigneeName: 'Anna',
            links,
        },
    };
}

function mountRow(entry: TimelineEntry, fields: FieldDefinition[] = []) {
    return mount(TimelineEntryRow, {
        props: { entry, recordId, fields },
        global: { stubs: { UiExtensionPoint: { template: '<slot />' } } },
    });
}

describe('TimelineEntryRow activity quick links', () => {
    it('links an activity on the timeline to its source but not back to the record', () => {
        const wrapper = mountRow(
            activityEntry([
                {
                    key: 'record',
                    id: recordId,
                    label: 'C-1',
                    href: `/nubos/records/${recordId}`,
                },
                {
                    key: 'email_message',
                    id: 'mail-1',
                    label: 'E-Mail: Angebot',
                    href: '/nubos/mails/mail-1',
                },
            ]),
        );

        const links = wrapper.findAll('[data-source-link]');

        expect(links).toHaveLength(1);
        expect(links[0].attributes('href')).toBe('/nubos/mails/mail-1');
    });

    it('shows no link for an activity without a visible source', () => {
        const wrapper = mountRow(activityEntry([]));

        expect(wrapper.find('[data-source-link]').exists()).toBe(false);
    });
});

describe('TimelineEntryRow — the snake_case payloads', () => {
    it('shows resolved owner names while retaining the underlying identifiers', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'field_change',
            payload: {
                field_key: 'owner_id',
                old_value: 'old-owner-id',
                new_value: 'new-owner-id',
                old_label: 'Ada Alt',
                new_label: 'Bea Neu',
            },
        });

        expect(wrapper.text()).toMatch(/Ada Alt\s*→\s*Bea Neu/);
        expect(wrapper.text()).not.toContain('owner-id');
    });

    it('renders a field change as label, old value and new value', () => {
        const wrapper = mountRow(
            {
                ...base,
                sourceKey: 'field_change',
                payload: {
                    field_key: 'titel',
                    old_value: 'Angebot A',
                    new_value: 'Angebot B',
                },
            },
            [titleField],
        );

        expect(wrapper.get('[data-timeline-entry]').attributes()).toMatchObject(
            {
                'data-timeline-entry-id': 'tl-1',
                'data-timeline-source': 'field_change',
            },
        );
        expect(wrapper.text()).toContain('Titel');
        expect(wrapper.text()).toMatch(/Angebot A\s*→\s*Angebot B/);
    });

    it('falls back to the raw key when the field definition is gone', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'field_change',
            payload: {
                field_key: 'geloeschtes_feld',
                old_value: 'A',
                new_value: 'B',
            },
        });

        expect(wrapper.text()).toContain('geloeschtes_feld');
    });

    it('renders a stage change through its tenant labels and drops the stored colour', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'stage_change',
            payload: {
                old_stage: {
                    id: '01STATUS000000000000000AAA',
                    key: 'offen',
                    labels: { de: 'Offen', en: 'Open' },
                    color: '#ff0000',
                },
                new_stage: {
                    id: '01STATUS000000000000000BBB',
                    key: 'gewonnen',
                    labels: { de: 'Gewonnen', en: 'Won' },
                    color: '#00ff00',
                },
            },
        });

        expect(wrapper.text()).toContain('Offen');
        expect(wrapper.text()).toContain('Gewonnen');
        expect(wrapper.html()).not.toContain('#ff0000');
        expect(wrapper.html()).not.toContain('#00ff00');
    });
});

describe('TimelineEntryRow — the camelCase payloads', () => {
    it('renders a note with its author and its body', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'note',
            sourceId: '01NOTE00000000000000000001',
            payload: {
                authorId: '01USER00000000000000000001',
                authorName: 'Mara Kessler',
                body: 'Kunde hat mündlich zugesagt.',
            },
        });

        expect(wrapper.text()).toContain('Mara Kessler');
        expect(wrapper.text()).toContain('Kunde hat mündlich zugesagt.');
    });

    it('renders an overdue reminder with its due date and its warning', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'reminder',
            sourceId: '01REMINDER000000000000001A',
            payload: {
                state: 'due',
                subject: 'Rückruf vereinbaren',
                dueAt: '2026-03-05T09:00:00.000000Z',
                overdue: true,
                assigneeId: null,
            },
        });

        expect(wrapper.text()).toContain('Rückruf vereinbaren');
        expect(wrapper.text()).toMatch(/2026/);
        expect(wrapper.text()).toContain('Überfällig');
    });

    it('leaves the overdue warning off a reminder that is not overdue', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'reminder',
            sourceId: '01REMINDER000000000000001A',
            payload: {
                state: 'created',
                subject: 'Rückruf vereinbaren',
                dueAt: '2026-03-05T09:00:00.000000Z',
                overdue: false,
                assigneeId: null,
            },
        });

        expect(wrapper.text()).not.toContain('Überfällig');
    });

    it('tells the reminder states apart without showing their raw keys', () => {
        const created = mountRow({
            ...base,
            sourceKey: 'reminder',
            sourceId: '01REMINDER000000000000001A',
            payload: {
                state: 'created',
                subject: 'Rückruf vereinbaren',
                dueAt: null,
                overdue: false,
            },
        });

        const completed = mountRow({
            ...base,
            sourceKey: 'reminder',
            sourceId: '01REMINDER000000000000001A',
            payload: {
                state: 'completed',
                subject: 'Rückruf vereinbaren',
                dueAt: null,
                overdue: false,
            },
        });

        expect(created.text()).not.toBe(completed.text());
        expect(created.text()).not.toContain('created');
        expect(completed.text()).not.toContain('completed');
    });

    it('renders an uploaded file with its name', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'file',
            sourceId: '01FILE00000000000000000001',
            payload: {
                fileName: 'Angebot.pdf',
                size: 2048,
                mimeType: 'application/pdf',
                fieldKey: 'anhang',
            },
        });

        expect(wrapper.text()).toContain('Angebot.pdf');
    });
});

describe('TimelineEntryRow — rows that carry nothing usable', () => {
    it('renders an unknown source key neutrally instead of breaking', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'webinar',
            payload: { attendees: 12 },
        });

        expect(wrapper.get('[data-timeline-entry]').attributes()).toMatchObject(
            {
                'data-timeline-entry-id': 'tl-1',
                'data-timeline-source': 'webinar',
            },
        );
        expect(wrapper.text()).not.toContain('webinar');
    });

    it('survives a row without any payload', () => {
        for (const sourceKey of [
            'field_change',
            'stage_change',
            'note',
            'reminder',
            'file',
        ]) {
            const wrapper = mountRow({ ...base, sourceKey, payload: null });

            expect(wrapper.find('[data-timeline-entry]').exists()).toBe(true);
        }
    });

    it('survives a row whose payload is empty', () => {
        for (const sourceKey of [
            'field_change',
            'stage_change',
            'note',
            'reminder',
            'file',
        ]) {
            const wrapper = mountRow({ ...base, sourceKey, payload: {} });

            expect(wrapper.find('[data-timeline-entry]').exists()).toBe(true);
        }
    });
});

describe('TimelineEntryRow — who did it and through which channel', () => {
    it('names the actor and the web app behind a browser change', () => {
        const meta = mountRow(
            {
                ...base,
                sourceKey: 'field_change',
                payload: { field_key: 'titel', old_value: 'A', new_value: 'B' },
            },
            [titleField],
        )
            .get('[data-timeline-meta]')
            .text();

        expect(meta).toContain('Mara Kessler');
        expect(meta).toContain('(Web App)');
    });

    it('names the api behind a change that came through it', () => {
        const meta = mountRow({
            ...base,
            channel: 'api',
            sourceKey: 'field_change',
            payload: { field_key: 'titel', old_value: 'A', new_value: 'B' },
        })
            .get('[data-timeline-meta]')
            .text();

        expect(meta).toContain('Mara Kessler');
        expect(meta).toContain('(API)');
    });

    it('keeps the channel out of the line for a system change', () => {
        const meta = mountRow({
            ...base,
            channel: 'system',
            actorLabel: null,
            sourceKey: 'field_change',
            payload: { field_key: 'titel', old_value: 'A', new_value: 'B' },
        })
            .get('[data-timeline-meta]')
            .text();

        expect(meta).toContain('System');
        expect(meta).not.toContain('(');
    });

    it('reads a stage change as a change of the stage field', () => {
        const title = mountRow({
            ...base,
            sourceKey: 'stage_change',
            payload: {
                old_stage: { key: 'open', labels: { de: 'Offen' } },
                new_stage: { key: 'won', labels: { de: 'Gewonnen' } },
            },
        })
            .get('[data-timeline-title]')
            .text();

        expect(title).toContain('Stage:');
        expect(title).toContain('Offen');
        expect(title).toContain('Gewonnen');
    });
});

describe('TimelineEntryRow — a change reads like the table it came from', () => {
    it.each<[string, FieldDefinition, unknown, unknown, string[], string[]]>([
        [
            'reads an amount as German currency instead of a raw number',
            definition({ key: 'betrag', field_type: 'money', label: 'Betrag' }),
            '1200',
            '1999.99',
            ['1.999,99 €', '1.200,00 €'],
            ['1999.99'],
        ],
        [
            'reads a decimal with a German comma',
            definition({
                key: 'dezimal',
                field_type: 'decimal',
                label: 'Dezimal',
            }),
            null,
            '12.75',
            ['12,75'],
            ['12.75'],
        ],
        [
            'reads a date as a German day',
            definition({ key: 'faellig', field_type: 'date', label: 'Fällig' }),
            null,
            '2026-08-28',
            ['28.08.2026'],
            ['2026-08-28'],
        ],
        [
            'reads a boolean as Ja instead of true',
            definition({ key: 'aktiv', field_type: 'boolean', label: 'Aktiv' }),
            false,
            true,
            ['Ja', 'Nein'],
            ['true'],
        ],
        [
            'reads a select through its option label',
            definition({
                key: 'stufe',
                field_type: 'single_select',
                label: 'Stufe',
                config: { options: [{ value: 'won', label: 'Gewonnen' }] },
            }),
            null,
            'won',
            ['Gewonnen'],
            ['—→won'],
        ],
        [
            'still marks an absent value with a dash',
            definition({ key: 'betrag', field_type: 'money', label: 'Betrag' }),
            null,
            '10',
            ['—'],
            [],
        ],
    ])('%s', (_case, field, oldValue, newValue, shownTexts, hiddenTexts) => {
        const text = mountRow(
            {
                ...base,
                sourceKey: 'field_change',
                payload: {
                    field_key: field.key,
                    old_value: oldValue,
                    new_value: newValue,
                },
            },
            [field],
        ).text();

        for (const shownText of shownTexts) {
            expect(text).toContain(shownText);
        }

        for (const hiddenText of hiddenTexts) {
            expect(text).not.toContain(hiddenText);
        }
    });
});

describe('TimelineEntryRow — a link between two records', () => {
    const counterpartId = '01RECORD00000000000000000B';

    function relationRow(action: 'linked' | 'unlinked') {
        return mountRow({
            ...base,
            sourceKey: 'relation',
            sourceId: '01LINK0000000000000000000A',
            payload: {
                action,
                relationship_type_id: '01RELTYPE000000000000000A',
                relationship_name: 'Ansprechpartner',
                counterpart_id: counterpartId,
                counterpart_number: 'PER-0007',
            },
        });
    }

    it('names the relationship and the counterpart when a link is made', () => {
        const text = relationRow('linked').text();

        expect(text).toContain('Verknüpft');
        expect(text).toContain('Ansprechpartner');
        expect(text).toContain('PER-0007');
    });

    it('says so when the link is removed again', () => {
        const text = relationRow('unlinked').text();

        expect(text).toContain('Verknüpfung entfernt');
        expect(text).not.toContain('Ereignis');
    });

    it('links the counterpart so the user can open it', () => {
        const link = relationRow('linked').get('[data-timeline-relation-link]');

        expect(link.attributes('href')).toContain(counterpartId);
    });
});

describe('TimelineEntryRow — a system field is not a column name', () => {
    it('names a soft delete in German instead of showing deleted_at', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'field_change',
            payload: {
                field_key: 'deleted_at',
                old_value: null,
                new_value: '2026-09-01T15:53:54+00:00',
            },
        });

        expect(wrapper.get('[data-timeline-title]').text()).toContain(
            'Gelöscht am',
        );
        expect(wrapper.text()).not.toContain('deleted_at');
    });

    it('reads the stored UTC timestamp as a local German moment', () => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'field_change',
            payload: {
                field_key: 'deleted_at',
                old_value: null,
                new_value: '2026-09-01T15:53:54+00:00',
            },
        });

        expect(wrapper.get('[data-timeline-title]').text()).not.toContain(
            '2026-09-01T15:53:54+00:00',
        );
        expect(wrapper.get('[data-timeline-title]').text()).toContain('2026');
    });

    it.each([
        ['owner_id', 'Zuständigkeit'],
        ['pipeline_id', 'Pipeline'],
        ['team_id', 'Team'],
        ['business_key', 'Datensatznummer'],
    ])('names the system field %s as %s', (key, label) => {
        const wrapper = mountRow({
            ...base,
            sourceKey: 'field_change',
            payload: { field_key: key, old_value: null, new_value: 'A' },
        });

        expect(wrapper.get('[data-timeline-title]').text()).toContain(label);
        expect(wrapper.get('[data-timeline-title]').text()).not.toContain(key);
    });

    it('still prefers the tenant field label over the system map', () => {
        const wrapper = mountRow(
            {
                ...base,
                sourceKey: 'field_change',
                payload: {
                    field_key: 'owner_id',
                    old_value: null,
                    new_value: 'A',
                },
            },
            [definition({ key: 'owner_id', label: 'Betreuer' })],
        );

        expect(wrapper.get('[data-timeline-title]').text()).toContain(
            'Betreuer',
        );
    });
});
