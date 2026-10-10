import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DashboardDetailsSheet from '@/components/dashboards/DashboardDetailsSheet.vue';
import type { DashboardRow } from '@/types/dashboards';
import { setUrlDefaults } from '@/wayfinder';

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const form = vi.hoisted(() => ({
    put: vi.fn(),
    transform: vi.fn(),
    state: { processing: false, errors: {} as Record<string, string> },
    payload: null as unknown,
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    useForm: () => {
        const instance = reactive({
            ...form.state,
            transform(callback: () => unknown) {
                form.payload = callback();

                return instance;
            },
            put: form.put,
        });

        return instance;
    },
}));

const { toastSuccess } = vi.hoisted(() => ({ toastSuccess: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { success: toastSuccess, error: vi.fn() },
}));

type Wrapper = VueWrapper;

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Sheet: {
        name: 'Sheet',
        props: ['open'],
        emits: ['update:open'],
        template: '<div><slot /></div>',
    },
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
    UnsavedChangesDialog: {
        props: ['open'],
        emits: ['confirm', 'cancel'],
        template:
            '<div v-if="open" data-unsaved-changes><button data-unsaved-confirm @click="$emit(\'confirm\')" /><button data-unsaved-cancel @click="$emit(\'cancel\')" /></div>',
    },
};

function dashboard(overrides: Partial<DashboardRow> = {}): DashboardRow {
    return {
        id: DASHBOARD_ID,
        name: 'Vertriebsübersicht',
        description: 'Kennzahlen des laufenden Quartals',
        owner_id: '01USER00000000000000001A',
        is_owner: true,
        is_tenant_wide: false,
        is_default: false,
        can_update: true,
        can_delete: true,
        can_share: true,
        update_reason: null,
        delete_reason: null,
        share_reason: null,
        has_definer_widget: false,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountSheet(open = true): Wrapper {
    return mount(DashboardDetailsSheet, {
        props: { dashboard: dashboard(), open },
        global: { stubs },
    });
}

async function typeName(wrapper: Wrapper, value: string): Promise<void> {
    await wrapper.get('#dashboard-name').setValue(value);
}

beforeEach(() => {
    form.put.mockReset();
    form.payload = null;
    toastSuccess.mockReset();
});

describe('DashboardDetailsSheet — what it shows', () => {
    it('seeds name and description from the dashboard the server sent', () => {
        const wrapper = mountSheet();

        expect(
            (wrapper.get('#dashboard-name').element as HTMLInputElement).value,
        ).toBe(dashboard().name);
        expect(
            (
                wrapper.get('#dashboard-description')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe(dashboard().description);
    });

    it('keeps the save button disabled until something actually changed', async () => {
        const wrapper = mountSheet();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await typeName(wrapper, 'Neuer Name');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });
});

describe('DashboardDetailsSheet — saving', () => {
    it('writes through the update endpoint of that very dashboard', async () => {
        const wrapper = mountSheet();

        await typeName(wrapper, 'Neuer Name');
        await wrapper.get('[data-form-save]').trigger('click');

        expect(form.put).toHaveBeenCalledTimes(1);
        expect(form.put.mock.calls[0][0]).toBe(
            DashboardsController.update.url({ dashboard: DASHBOARD_ID }),
        );
        expect(form.payload).toEqual({
            name: 'Neuer Name',
            description: dashboard().description,
        });
    });

    it('sends an empty description as null instead of an empty string', async () => {
        const wrapper = mountSheet();

        await wrapper.get('#dashboard-description').setValue('   ');
        await wrapper.get('[data-form-save]').trigger('click');

        expect(form.payload).toEqual({
            name: dashboard().name,
            description: null,
        });
    });

    it('confirms in German and closes once the server accepted the write', async () => {
        const wrapper = mountSheet();

        await typeName(wrapper, 'Neuer Name');
        await wrapper.get('[data-form-save]').trigger('click');

        const options = form.put.mock.calls[0][1] as {
            preserveScroll: boolean;
            onSuccess: () => void;
        };

        expect(options.preserveScroll).toBe(true);

        options.onSuccess();
        await nextTick();

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(String(toastSuccess.mock.calls[0][0])).toMatch(/gespeichert/);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('DashboardDetailsSheet — leaving', () => {
    it('closes straight away when nothing was changed', async () => {
        const wrapper = mountSheet();

        await wrapper.get('[data-form-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(wrapper.find('[data-unsaved-changes]').exists()).toBe(false);
    });

    it('asks before dropping pending changes and only then closes', async () => {
        const wrapper = mountSheet();

        await typeName(wrapper, 'Neuer Name');
        await wrapper.get('[data-form-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.get('[data-unsaved-changes]').isVisible()).toBe(true);

        await wrapper.get('[data-unsaved-confirm]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('reseeds the fields when the panel is opened again', async () => {
        const wrapper = mountSheet(false);

        await wrapper.setProps({ open: true });
        await typeName(wrapper, 'Verworfener Name');
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(
            (wrapper.get('#dashboard-name').element as HTMLInputElement).value,
        ).toBe(dashboard().name);
        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();
    });
});
