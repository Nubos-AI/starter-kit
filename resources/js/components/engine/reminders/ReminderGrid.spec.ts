import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ReminderGrid from '@/components/engine/reminders/ReminderGrid.vue';
import type { ReminderItem } from '@/composables/useReminders';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: null, can: {} } } }),
}));

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function item(overrides: Partial<ReminderItem> = {}): ReminderItem {
    return {
        id: '01REMIND0000000000000000A1',
        subject: 'Angebot nachfassen',
        note: null,
        type: null,
        dueAt: null,
        doneAt: null,
        owner: null,
        assignee: null,
        record: null,
        links: [],
        ...overrides,
    };
}

function renderLinksCell(row: ReminderItem): Wrapper {
    const grid = mount(ReminderGrid, {
        props: { items: [row] },
        global: { stubs: { DataGrid: true } },
    });
    mounted.push(grid);

    const columns = (
        grid.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<ReminderItem>[];
        }
    ).props('columnDefs');
    const linksColumn = columns.find((column) => column.colId === 'links');
    const cell = mount(linksColumn?.cellRenderer as Component, {
        props: { params: { data: row } },
    });
    mounted.push(cell);

    return cell;
}

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('ReminderGrid quick links', () => {
    it('jumps straight to the record and to the source of a reminder', () => {
        const cell = renderLinksCell(
            item({
                links: [
                    {
                        key: 'record',
                        id: '01RECORD00000000000000000A',
                        label: 'C-42',
                        href: '/nubos/records/01RECORD00000000000000000A',
                    },
                    {
                        key: 'email_message',
                        id: '01MAIL00000000000000000000',
                        label: 'E-Mail: Angebot',
                        href: '/nubos/mails/01MAIL00000000000000000000',
                    },
                ],
            }),
        );

        const links = cell.findAll('[data-source-link]');

        expect(
            links.map((link) => link.attributes('data-source-link')),
        ).toEqual(['record', 'email_message']);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/nubos/records/01RECORD00000000000000000A',
            '/nubos/mails/01MAIL00000000000000000000',
        ]);
        expect(links[0].text()).toBe('C-42');
    });

    it('offers no link when the server resolved no target the user may open', () => {
        const cell = renderLinksCell(item());

        expect(cell.find('[data-source-link]').exists()).toBe(false);
        expect(cell.text()).toBe('—');
    });

    it('names a deleted record without linking to it', () => {
        const cell = renderLinksCell(
            item({
                record: {
                    id: '01RECORD00000000000000000A',
                    label: 'C-42',
                    deleted: true,
                },
            }),
        );

        expect(cell.find('[data-source-link]').exists()).toBe(false);
        expect(cell.find('[data-reminder-deleted-record]').text()).toContain(
            'C-42',
        );
    });
});
