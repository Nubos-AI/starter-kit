import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BulkConfirmDialog from '@/components/engine/BulkConfirmDialog.vue';
import type { BulkAction } from '@/composables/useRecordSelection';
import type { FieldDefinition } from '@/types/fields';

const passthrough = { template: '<div><slot /></div>' };

const SelectStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
        '<div><button type="button" class="pick-field" @click="$emit(\'update:modelValue\', \'stage\')">pick</button><slot /></div>',
};

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
    Select: SelectStub,
    SelectTrigger: passthrough,
    SelectContent: passthrough,
    SelectItem: passthrough,
    SelectValue: passthrough,
};

const fieldDefinitions: FieldDefinition[] = [
    {
        key: 'stage',
        field_type: 'single_select',
        label: 'Stage',
        is_required: false,
    },
    {
        key: 'owner',
        field_type: 'relation_has_many',
        label: 'Owner',
        is_required: false,
    },
];

function mountDialog(action: BulkAction, extra: Record<string, unknown> = {}) {
    return mount(BulkConfirmDialog, {
        props: {
            open: true,
            action,
            countLabel: '3 Datensätze',
            fieldDefinitions,
            ...extra,
        },
        global: { stubs },
    });
}

function buttonByText(wrapper: ReturnType<typeof mount>, text: string) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

describe('BulkConfirmDialog — effect description (SC-11)', () => {
    it('shows the action title, effect wording and affected count', () => {
        const wrapper = mountDialog('soft-delete');

        expect(wrapper.text()).toContain('Datensätze löschen');
        expect(wrapper.text()).toContain('Papierkorb');
        expect(wrapper.text()).toContain('Betroffen: 3 Datensätze');
    });

    it('marks a soft-delete as destructive with an explicit warning', () => {
        const wrapper = mountDialog('soft-delete');
        expect(wrapper.text()).toContain('Achtung');
    });

    it('describes the CSV export effect', () => {
        const wrapper = mountDialog('export-csv');
        expect(wrapper.text()).toContain('CSV');
    });
});

describe('BulkConfirmDialog — confirm contract (SC-11)', () => {
    it('emits confirm and closes for a non-field action', async () => {
        const wrapper = mountDialog('soft-delete');

        await buttonByText(wrapper, 'Bestätigen')!.trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('confirm')![0]).toEqual([]);
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('cancels without confirming', async () => {
        const wrapper = mountDialog('restore');

        await buttonByText(wrapper, 'Abbrechen')!.trigger('click');

        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('blocks confirm for set-field until a field is chosen', async () => {
        const wrapper = mountDialog('set-field');

        const confirm = buttonByText(wrapper, 'Bestätigen')!;
        expect(confirm.attributes('disabled')).toBeDefined();

        await confirm.trigger('click');
        expect(wrapper.emitted('confirm')).toBeUndefined();
    });

    it('emits the chosen field/value payload for set-field', async () => {
        const wrapper = mountDialog('set-field');

        await wrapper.find('.pick-field').trigger('click');
        await wrapper.find('input').setValue('won');
        await buttonByText(wrapper, 'Bestätigen')!.trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('confirm')![0]).toEqual([{ stage: 'won' }]);
    });

    it('excludes complex fields from the set-field target list', () => {
        const wrapper = mountDialog('set-field');
        expect(wrapper.text()).not.toContain('Owner');
    });
});

describe('BulkConfirmDialog — the deletion reason follows the object type', () => {
    it('asks for no reason when the object type does not demand one', () => {
        const wrapper = mountDialog('soft-delete');

        expect(
            wrapper.find('[data-testid="bulk-deletion-reason"]').exists(),
        ).toBe(false);
    });

    it('asks for a reason when the object type demands one', () => {
        const wrapper = mountDialog('soft-delete', {
            requiresDeletionReason: true,
        });

        expect(
            wrapper.find('[data-testid="bulk-deletion-reason"]').exists(),
        ).toBe(true);
    });

    it('keeps confirm disabled until a reason is typed', async () => {
        const wrapper = mountDialog('soft-delete', {
            requiresDeletionReason: true,
        });

        expect(
            buttonByText(wrapper, 'Bestätigen')?.attributes('disabled'),
        ).toBe('');

        await wrapper
            .find('[data-testid="bulk-deletion-reason"]')
            .setValue('Massenbereinigung');

        expect(
            buttonByText(wrapper, 'Bestätigen')?.attributes('disabled'),
        ).toBeUndefined();
    });

    it('sends the reason along with the confirmation', async () => {
        const wrapper = mountDialog('soft-delete', {
            requiresDeletionReason: true,
        });

        await wrapper
            .find('[data-testid="bulk-deletion-reason"]')
            .setValue('Massenbereinigung');
        await buttonByText(wrapper, 'Bestätigen')?.trigger('click');

        expect(wrapper.emitted('confirm')?.[0]).toEqual([
            { deletion_reason: 'Massenbereinigung' },
        ]);
    });

    it('asks for no reason on a restore', () => {
        const wrapper = mountDialog('restore', {
            requiresDeletionReason: true,
        });

        expect(
            wrapper.find('[data-testid="bulk-deletion-reason"]').exists(),
        ).toBe(false);
    });
});
