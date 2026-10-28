import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';

function buttons(): HTMLButtonElement[] {
    return Array.from(document.body.querySelectorAll('button'));
}

function buttonWith(label: string): HTMLButtonElement {
    return buttons().find((button) => button.textContent?.trim() === label)!;
}

describe('UnsavedChangesDialog', () => {
    it('stays closed while there is nothing to confirm', async () => {
        mount(UnsavedChangesDialog, { props: { open: false } });
        await nextTick();

        expect(document.body.textContent).not.toContain(
            'Änderungen verwerfen?',
        );
    });

    it('offers leaving and staying', async () => {
        const wrapper = mount(UnsavedChangesDialog, { props: { open: true } });
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');

        buttonWith('Ja, verlassen').click();
        buttonWith('Nein, hier bleiben').click();

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
