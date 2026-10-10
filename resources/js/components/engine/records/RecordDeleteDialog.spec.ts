import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordDeleteDialog from '@/components/engine/records/RecordDeleteDialog.vue';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function mountDialog(props: Record<string, unknown> = {}) {
    return mount(RecordDeleteDialog, {
        props: {
            open: true,
            description: 'Der Datensatz wird gelöscht.',
            reason: '',
            'onUpdate:reason': () => undefined,
            ...props,
        },
        global: { stubs },
    });
}

describe('RecordDeleteDialog — the reason follows the object type', () => {
    it('omits the reason field when the object type does not demand one', () => {
        expect(
            mountDialog().find('[data-testid="deletion-reason"]').exists(),
        ).toBe(false);
    });

    it('offers the reason field when the object type demands one', () => {
        expect(
            mountDialog({ requiresReason: true })
                .find('[data-testid="deletion-reason"]')
                .exists(),
        ).toBe(true);
    });

    it('reports the typed reason back to the caller', async () => {
        const wrapper = mountDialog({ requiresReason: true });

        await wrapper
            .find('[data-testid="deletion-reason"]')
            .setValue('Dublette');

        expect(wrapper.emitted('update:reason')?.at(-1)).toEqual(['Dublette']);
    });

    it('shows the error the server answered with', () => {
        const wrapper = mountDialog({
            requiresReason: true,
            error: 'Bitte geben Sie einen Grund für die Löschung an.',
        });

        expect(wrapper.text()).toContain('Bitte geben Sie einen Grund');
    });
});
