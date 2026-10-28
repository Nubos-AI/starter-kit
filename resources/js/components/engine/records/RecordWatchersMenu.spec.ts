import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { WatcherItem } from '@/composables/useWatchers';
import { comboboxPrimitiveStubs } from '@/tests/comboboxPrimitiveStubs';
import type { SelectOption } from '@/types/ui';

const items = ref<WatcherItem[]>([]);
const loading = ref<boolean>(false);
const error = ref<string | null>(null);
const following = ref<boolean>(false);
const loadForRecord = vi.fn();
const syncWatchers = vi.fn(() => Promise.resolve(true));

vi.mock('@/composables/useWatchers', () => ({
    useWatchers: () => ({
        items,
        loading,
        error,
        following,
        loadForRecord,
        follow: vi.fn(),
        unfollow: vi.fn(),
        addWatcher: vi.fn(),
        syncWatchers,
    }),
}));

const RecordWatchersMenu = (
    await import('@/components/engine/records/RecordWatchersMenu.vue')
).default;

const people: SelectOption[] = [
    {
        value: 'u-1',
        label: 'Anna Albers',
        description: 'anna@nubos.de',
        avatar: { name: 'Anna Albers' },
    },
    {
        value: 'u-2',
        label: 'Sven Support',
        description: 'sven@nubos.de',
        avatar: { name: 'Sven Support' },
    },
];

function watcher(
    id: string,
    userId: string,
    label: string,
    source = 'manual',
): WatcherItem {
    return {
        id,
        source,
        user: { id: userId, label },
        createdAt: null,
    };
}

function mountMenu(
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(RecordWatchersMenu, {
        props: { recordId: 'rec-1', options: people, ...props },
        global: { stubs: comboboxPrimitiveStubs },
    });
}

function label(wrapper: ReturnType<typeof mount>): string {
    return wrapper.get('[data-people-trigger-label]').text();
}

describe('RecordWatchersMenu', () => {
    it('loads the watchers of the record on mount', () => {
        loadForRecord.mockClear();
        items.value = [];

        mountMenu();

        expect(loadForRecord).toHaveBeenCalledWith('rec-1');
    });

    it('says so while nobody watches', () => {
        items.value = [];

        const wrapper = mountMenu();

        expect(label(wrapper)).toBe('Keine Beobachter');
        expect(wrapper.get('[data-people-trigger-caption]').text()).toBe(
            'Beobachter',
        );
    });

    it('names the single watcher and counts the many', () => {
        items.value = [watcher('w-1', 'u-1', 'Anna Albers')];
        expect(label(mountMenu())).toBe('Anna Albers');

        items.value = [
            watcher('w-1', 'u-1', 'Anna Albers'),
            watcher('w-2', 'u-2', 'Sven Support'),
        ];
        expect(label(mountMenu())).toBe('2 Beobachter');
    });

    it('offers every person of the record in the menu', () => {
        items.value = [];

        const wrapper = mountMenu();

        expect(
            wrapper
                .findAll('[data-multi-select-item]')
                .map((item) => item.text()),
        ).toEqual([
            expect.stringContaining('Anna Albers'),
            expect.stringContaining('Sven Support'),
        ]);
    });

    it('adds a watcher through the same list that removes one', async () => {
        items.value = [watcher('w-1', 'u-1', 'Anna Albers')];
        syncWatchers.mockClear();

        const wrapper = mountMenu();

        await wrapper.findAll('[data-multi-select-item]')[1].trigger('click');

        expect(syncWatchers).toHaveBeenCalledWith('rec-1', ['u-1', 'u-2']);
    });

    it('removes a watcher by unpicking the person', async () => {
        items.value = [
            watcher('w-1', 'u-1', 'Anna Albers'),
            watcher('w-2', 'u-2', 'Sven Support'),
        ];
        syncWatchers.mockClear();

        const wrapper = mountMenu();

        await wrapper.findAll('[data-multi-select-item]')[0].trigger('click');

        expect(syncWatchers).toHaveBeenCalledWith('rec-1', ['u-2']);
    });

    it('keeps a watcher who is no longer an option selectable in the list', () => {
        items.value = [watcher('w-9', 'u-9', 'Ehemalige Person')];

        const wrapper = mountMenu();

        expect(
            wrapper
                .findAll('[data-multi-select-item]')
                .map((item) => item.text()),
        ).toEqual([
            expect.stringContaining('Anna Albers'),
            expect.stringContaining('Sven Support'),
            expect.stringContaining('Ehemalige Person'),
        ]);
    });

    it('leaves owner and collaborators out of the watcher list', () => {
        items.value = [
            watcher('w-1', 'u-1', 'Anna Albers', 'auto'),
            watcher('w-2', 'u-2', 'Sven Support', 'collaborator'),
            watcher('w-3', 'u-3', 'Rita Vertrieb'),
        ];

        const wrapper = mountMenu({
            options: [
                ...people,
                {
                    value: 'u-3',
                    label: 'Rita Vertrieb',
                    avatar: { name: 'Rita Vertrieb' },
                },
            ],
        });

        expect(label(wrapper)).toBe('Rita Vertrieb');
        expect(wrapper.get('[data-people-trigger-label]').text()).not.toContain(
            'Anna Albers',
        );
    });

    it('syncs only the manual watchers back to the server', async () => {
        items.value = [
            watcher('w-1', 'u-1', 'Anna Albers', 'auto'),
            watcher('w-3', 'u-3', 'Rita Vertrieb'),
        ];
        syncWatchers.mockClear();

        const wrapper = mountMenu({
            options: [
                {
                    value: 'u-2',
                    label: 'Sven Support',
                    avatar: { name: 'Sven Support' },
                },
                {
                    value: 'u-3',
                    label: 'Rita Vertrieb',
                    avatar: { name: 'Rita Vertrieb' },
                },
            ],
        });

        await wrapper.findAll('[data-multi-select-item]')[0].trigger('click');

        expect(syncWatchers).toHaveBeenCalledWith('rec-1', ['u-3', 'u-2']);
    });

    it('changes nothing on a record the viewer may not manage', async () => {
        items.value = [];
        syncWatchers.mockClear();

        const wrapper = mountMenu({ disabled: true });

        expect(
            wrapper.get('[data-people-trigger]').attributes('disabled'),
        ).toBeDefined();
        expect(syncWatchers).not.toHaveBeenCalled();
    });
});
