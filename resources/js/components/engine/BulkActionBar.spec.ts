import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import BulkActionBar from '@/components/engine/BulkActionBar.vue';
import BulkConfirmDialog from '@/components/engine/BulkConfirmDialog.vue';
import type { SelectionSummary } from '@/composables/useRecordSelection';
import type { RecordObjectType } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => pageState,
}));

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null,
            },
        },
    },
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

vi.mock('@/composables/useBatchProgress', () => ({
    useBatchProgress: () => ({
        start: vi.fn(),
        status: ref('idle'),
        progress: ref(0),
        processedJobs: ref(0),
        totalJobs: ref(0),
        failedJobs: ref(0),
        partialErrors: ref([]),
        active: ref(false),
    }),
}));

const objectType: RecordObjectType = {
    id: 'ot-1',
    key: 'companies',
    slug: 'companies',
    name: 'Companies',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

interface RecordAbilityFlags {
    create?: boolean;
    update?: boolean;
    delete?: boolean;
    import?: boolean;
    export?: boolean;
    rulesManage?: boolean;
}

function abilities(
    overrides: RecordAbilityFlags = {},
): Record<string, boolean> {
    const flags = {
        create: true,
        update: true,
        delete: true,
        import: true,
        export: true,
        rulesManage: true,
        ...overrides,
    };

    return {
        'companies.create': flags.create,
        'companies.update': flags.update,
        'companies.delete': flags.delete,
        'companies.import': flags.import,
        'companies.export': flags.export,
        'companies.rules.manage': flags.rulesManage,
    };
}

type Wrapper = ReturnType<typeof mount>;

function mountBar(
    recordAbilities: Record<string, boolean>,
    summary: SelectionSummary = {
        mode: 'visible',
        count: 3,
        excludedCount: 0,
    },
): Wrapper {
    pageState.props.auth.can = recordAbilities;

    return mount(BulkActionBar, {
        props: {
            objectType,
            summary,
            fieldDefinitions: [],
            buildSelectionPayload: () => ({
                mode: 'visible' as const,
                includedIds: ['r1'],
            }),
        },
        global: {
            stubs: {
                BulkConfirmDialog: { template: '<div />' },
                Teleport: true,
            },
        },
    });
}

describe('BulkActionBar — every button carries its own right', () => {
    it.each<[string, RecordAbilityFlags, string[], string[]]>([
        [
            'offers every action on living records to a fully permitted user and never a restore',
            {},
            ['Feld setzen', 'CSV-Export', 'Löschen'],
            ['Wiederherstellen'],
        ],
        [
            'drops the delete action without the delete right',
            { delete: false },
            ['Feld setzen', 'CSV-Export'],
            ['Löschen', 'Wiederherstellen'],
        ],
        [
            'drops only the field action without the update right',
            { update: false },
            ['CSV-Export', 'Löschen'],
            ['Feld setzen', 'Wiederherstellen'],
        ],
        [
            'drops the export action without the export right',
            { export: false },
            ['Feld setzen', 'Löschen'],
            ['CSV-Export', 'Wiederherstellen'],
        ],
    ])('%s', (_case, flags, offeredLabels, droppedLabels) => {
        const text = mountBar(abilities(flags)).text();

        for (const label of offeredLabels) {
            expect(text).toContain(label);
        }

        for (const label of droppedLabels) {
            expect(text).not.toContain(label);
        }
    });
});

describe('BulkActionBar — how many records the selection covers', () => {
    it.each<[string, SelectionSummary, string]>([
        [
            'counts the rows picked one by one',
            { mode: 'visible', count: 3, excludedCount: 0 },
            '3 ausgewählt',
        ],
        [
            'shows the match total the server reported for all matches',
            { mode: 'all-matching', count: 41, excludedCount: 1 },
            '41 ausgewählt',
        ],
        [
            'names all matches without a number while the total is unknown',
            { mode: 'all-matching', count: null, excludedCount: 0 },
            'Alle Treffer ausgewählt',
        ],
        [
            'names the exclusions while the total is unknown',
            { mode: 'all-matching', count: null, excludedCount: 2 },
            'Alle Treffer außer 2 ausgewählt',
        ],
    ])('%s', (_case, summary, label) => {
        const badge = mountBar(abilities(), summary).find(
            '[data-selection-summary]',
        );

        expect(badge.text()).toBe(label);
    });
});

describe('BulkActionBar — the reason the server gave for refusing a bulk action', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.clearAllMocks();
    });

    async function refuse(status: number, body: unknown): Promise<void> {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: status >= 200 && status < 300,
                    status,
                    json: () => Promise.resolve(body),
                }),
            ),
        );

        const wrapper = mountBar(abilities());

        const trigger = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Löschen');

        await trigger?.trigger('click');
        await wrapper.findComponent(BulkConfirmDialog).vm.$emit('confirm');
        await flushPromises();
    }

    it('shows the message the server sent instead of a fixed sentence', async () => {
        await refuse(422, {
            message: 'Zu viele Datensätze für eine Massenaktion.',
        });

        expect(toast.error).toHaveBeenCalledWith(
            'Zu viele Datensätze für eine Massenaktion.',
        );
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        await refuse(500, {});

        expect(toast.error).toHaveBeenCalledWith(
            'Die Massenaktion konnte nicht gestartet werden. Bitte versuchen Sie es erneut.',
        );
    });
});
