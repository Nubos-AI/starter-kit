import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MaintenanceLockController from '@/actions/App/Http/Controllers/Maintenance/MaintenanceLockController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Show from '@/pages/maintenance/Show.vue';
import type { MaintenanceLockDetails } from '@/types/maintenance';

interface VisitOptions {
    onFinish?: () => void;
    onSuccess?: () => void;
}

interface FormStub {
    note: string;
    errors: Record<string, string>;
    processing: boolean;
}

const { deleteMock, formState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    formState: {
        post: vi.fn(),
        reset: vi.fn(),
        current: null as FormStub | null,
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: makeReactive } = await import('vue');

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        router: { on: vi.fn(() => vi.fn()), delete: deleteMock },
        useForm: (initial: { note: string }) => {
            const form = makeReactive({
                ...initial,
                errors: {} as Record<string, string>,
                processing: false,
                post: formState.post,
                reset: formState.reset,
            });

            formState.current = form;

            return form;
        },
    };
});

const passthrough = { template: '<div><slot /></div>' };

const DialogStub = {
    props: ['open'],
    emits: ['update:open'],
    template: '<div v-if="open" data-dialog-open><slot /></div>',
};

const stubs = {
    Dialog: DialogStub,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function activeLock(
    overrides: Partial<MaintenanceLockDetails> = {},
): MaintenanceLockDetails {
    return {
        id: '01MAINTENANCELOCK0000000001',
        reason: 'manual',
        reasonLabel: 'Manuell eingeschaltet',
        note: 'Datenbereinigung vor dem Quartalsabschluss',
        acquiredAt: '2030-01-05T09:30:00+00:00',
        acquiredByName: 'Ada Admin',
        ...overrides,
    };
}

function render(lock: MaintenanceLockDetails | null = null) {
    return mount(Show, { props: { lock }, global: { stubs } });
}

beforeEach(() => {
    deleteMock.mockReset();
    formState.post.mockReset();
    formState.reset.mockReset();
    formState.current = null;
});

describe('maintenance/Show — inactive', () => {
    it('states that the maintenance mode is off and offers the acquire form', () => {
        const wrapper = render();

        expect(
            wrapper.find('[data-testid="maintenance-inactive"]').text(),
        ).toBe('Der Wartungsmodus ist ausgeschaltet.');
        expect(
            wrapper.find('[data-testid="maintenance-acquire-card"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-testid="maintenance-release"]').exists(),
        ).toBe(false);
    });

    it('posts the entered reason to the store route', async () => {
        const wrapper = render();

        await wrapper
            .get('[data-testid="maintenance-note-input"]')
            .setValue('Import der Altdaten');
        await wrapper.get('form').trigger('submit');

        expect(formState.post).toHaveBeenCalledTimes(1);

        const [url, options] = formState.post.mock.calls[0] as [
            string,
            VisitOptions,
        ];

        expect(url).toBe(MaintenanceLockController.store.url());

        options.onSuccess?.();

        expect(formState.reset).toHaveBeenCalled();
    });

    it('shows the validation message of the reason', async () => {
        const wrapper = render();

        if (formState.current === null) {
            throw new Error('The page did not create its form.');
        }

        formState.current.errors.note =
            'Bitte geben Sie einen Grund für den Wartungsmodus an.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Bitte geben Sie einen Grund für den Wartungsmodus an.',
        );
    });
});

describe('maintenance/Show — active', () => {
    it('shows since when, by whom, the kind and the reason of the active lock', () => {
        const wrapper = render(activeLock());

        expect(
            wrapper.find('[data-testid="maintenance-since"]').text(),
        ).not.toBe('—');
        expect(
            wrapper.find('[data-testid="maintenance-acquired-by"]').text(),
        ).toBe('Ada Admin');
        expect(wrapper.find('[data-testid="maintenance-reason"]').text()).toBe(
            'Manuell eingeschaltet',
        );
        expect(wrapper.find('[data-testid="maintenance-note"]').text()).toBe(
            'Datenbereinigung vor dem Quartalsabschluss',
        );
        expect(
            wrapper.find('[data-testid="maintenance-acquire-card"]').exists(),
        ).toBe(false);
    });

    it('asks for confirmation before it deletes the lock through the destroy route', async () => {
        const wrapper = render(activeLock());

        expect(deleteMock).not.toHaveBeenCalled();

        await wrapper
            .get('[data-testid="maintenance-release"]')
            .trigger('click');

        const dialog = wrapper.getComponent(ConfirmDialog);

        expect(dialog.props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();

        dialog.vm.$emit('confirm');

        expect(deleteMock).toHaveBeenCalledTimes(1);

        const [url, options] = deleteMock.mock.calls[0] as [
            string,
            VisitOptions,
        ];

        expect(url).toBe(MaintenanceLockController.destroy.url());

        options.onFinish?.();
        await wrapper.vm.$nextTick();

        expect(wrapper.getComponent(ConfirmDialog).props('open')).toBe(false);
    });
});
