import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import BulkActionBar from '@/components/engine/BulkActionBar.vue';
import BulkConfirmDialog from '@/components/engine/BulkConfirmDialog.vue';
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

function mountBar(recordAbilities: Record<string, boolean>): Wrapper {
    pageState.props.auth.can = recordAbilities;

    return mount(BulkActionBar, {
        props: {
            objectType,
            summary: { mode: 'visible', label: '3 ausgewählt', count: 3 },
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
    it('offers all actions to a fully permitted user', () => {
        const text = mountBar(abilities()).text();

        expect(text).toContain('Feld setzen');
        expect(text).toContain('CSV-Export');
        expect(text).toContain('Löschen');
    });

    it('drops the delete action without the delete right', () => {
        expect(mountBar(abilities({ delete: false })).text()).not.toContain(
            'Löschen',
        );
    });

    it('drops the field and restore actions without the update right', () => {
        const text = mountBar(abilities({ update: false })).text();

        expect(text).not.toContain('Feld setzen');
        expect(text).not.toContain('Wiederherstellen');
    });

    it('drops the export action without the export right', () => {
        expect(mountBar(abilities({ export: false })).text()).not.toContain(
            'CSV-Export',
        );
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
