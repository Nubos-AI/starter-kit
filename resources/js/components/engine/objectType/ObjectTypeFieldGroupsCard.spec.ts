import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import FieldGroupsController from '@/actions/App/Http/Controllers/Engine/FieldGroupsController';
import ObjectTypeFieldGroupsCard from '@/components/engine/objectType/ObjectTypeFieldGroupsCard.vue';
import type { FieldGroupRow } from '@/types/fieldGroups';

const { postMock, putMock, deleteMock } = vi.hoisted(() => ({
    postMock: vi.fn(),
    putMock: vi.fn(),
    deleteMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post: postMock, put: putMock, delete: deleteMock },
}));

const SLUG = 'companies';

const address: FieldGroupRow = {
    id: 'fg-address',
    key: 'address',
    label: 'Adresse',
    description: null,
    position: 2,
};

const contact: FieldGroupRow = {
    id: 'fg-contact',
    key: 'contact',
    label: 'Kontakt',
    description: null,
    position: 1,
};

function mountCard(
    groups: FieldGroupRow[] = [address, contact],
    editable = true,
) {
    return mount(ObjectTypeFieldGroupsCard, {
        props: { objectTypeSlug: SLUG, groups, editable },
    });
}

describe('ObjectTypeFieldGroupsCard', () => {
    beforeEach(() => {
        postMock.mockClear();
        putMock.mockClear();
        deleteMock.mockClear();
    });

    it('lists the groups in position order', () => {
        const wrapper = mountCard();
        const rendered = wrapper
            .findAll('[data-field-group]')
            .map((row) => row.attributes('data-field-group'));

        expect(rendered).toEqual(['contact', 'address']);
    });

    it('explains the empty state instead of listing nothing', () => {
        expect(mountCard([]).text()).toContain(
            'Noch keine Feldgruppen angelegt',
        );
    });

    it('keeps the add button disabled until key and label are given', async () => {
        const wrapper = mountCard();
        const button = wrapper.get('[data-field-group-create]');

        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('#field-group-key').setValue('address');

        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('#field-group-label').setValue('Adresse');

        expect(button.attributes('disabled')).toBeUndefined();
    });

    it('creates a group with its key, its label and its description', async () => {
        const wrapper = mountCard();

        await wrapper.get('#field-group-key').setValue('address');
        await wrapper.get('#field-group-label').setValue('Adresse');
        await wrapper
            .get('#field-group-description')
            .setValue('Die Rechnungsanschrift.');
        await wrapper.get('[data-field-group-create]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            FieldGroupsController.store.url({ objectType: SLUG }),
            {
                key: 'address',
                label: 'Adresse',
                description: 'Die Rechnungsanschrift.',
            },
            expect.anything(),
        );
    });

    it('creates a group without a description, since it is optional', async () => {
        const wrapper = mountCard();

        await wrapper.get('#field-group-key').setValue('address');
        await wrapper.get('#field-group-label').setValue('Adresse');
        await wrapper.get('[data-field-group-create]').trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            FieldGroupsController.store.url({ objectType: SLUG }),
            { key: 'address', label: 'Adresse', description: '' },
            expect.anything(),
        );
    });

    it('shows the description a group already carries', () => {
        const wrapper = mountCard([
            { ...address, description: 'Die Rechnungsanschrift.' },
        ]);

        expect(
            (
                wrapper.get('[data-field-group-description="address"]')
                    .element as HTMLInputElement
            ).value,
        ).toBe('Die Rechnungsanschrift.');
    });

    it('saves a changed description on its own', async () => {
        const wrapper = mountCard([address]);
        const input = wrapper.get('[data-field-group-description="address"]');

        await input.setValue('Die Rechnungsanschrift.');
        await input.trigger('change');

        expect(putMock).toHaveBeenCalledWith(
            FieldGroupsController.update.url({
                objectType: SLUG,
                fieldGroup: address.id,
            }),
            { description: 'Die Rechnungsanschrift.' },
            expect.anything(),
        );
    });

    it('clears a description the user empties', async () => {
        const wrapper = mountCard([{ ...address, description: 'Alt' }]);
        const input = wrapper.get('[data-field-group-description="address"]');

        await input.setValue('');
        await input.trigger('change');

        expect(putMock).toHaveBeenCalledWith(
            FieldGroupsController.update.url({
                objectType: SLUG,
                fieldGroup: address.id,
            }),
            { description: '' },
            expect.anything(),
        );
    });

    it('sends nothing when the description is left as it was', async () => {
        const wrapper = mountCard([address]);

        await wrapper
            .get('[data-field-group-description="address"]')
            .trigger('change');

        expect(putMock).not.toHaveBeenCalled();
    });

    it('renames a group when its label input changes', async () => {
        const wrapper = mountCard([address]);
        const input = wrapper.get('[data-field-group="address"] input');

        await input.setValue('Anschrift');
        await input.trigger('change');

        expect(putMock).toHaveBeenCalledWith(
            FieldGroupsController.update.url({
                objectType: SLUG,
                fieldGroup: address.id,
            }),
            { label: 'Anschrift' },
            expect.anything(),
        );
    });

    it('sends no rename for an unchanged or emptied label', async () => {
        const wrapper = mountCard([address]);
        const input = wrapper.get('[data-field-group="address"] input');

        await input.trigger('change');
        await input.setValue('   ');
        await input.trigger('change');

        expect(putMock).not.toHaveBeenCalled();
    });

    it('asks before deleting and names what happens to the fields', async () => {
        const wrapper = mountCard([address]);

        await wrapper
            .get('[data-field-group-remove="address"]')
            .trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Feldgruppe löschen?');
        expect(document.body.textContent).toContain(
            'bleiben erhalten und stehen danach ohne Gruppe',
        );
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes the group once the dialog is confirmed', async () => {
        const wrapper = mountCard([address]);

        await wrapper
            .get('[data-field-group-remove="address"]')
            .trigger('click');
        await nextTick();

        wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm');
        await nextTick();

        expect(deleteMock).toHaveBeenCalledWith(
            FieldGroupsController.destroy.url({
                objectType: SLUG,
                fieldGroup: address.id,
            }),
            expect.anything(),
        );
    });

    it('offers neither creating nor deleting to a read-only viewer', () => {
        const wrapper = mountCard([address], false);

        expect(wrapper.find('[data-field-group-create]').exists()).toBe(false);
        expect(
            wrapper.find('[data-field-group-remove="address"]').exists(),
        ).toBe(false);
        expect(
            wrapper
                .get('[data-field-group="address"] input')
                .attributes('readonly'),
        ).toBeDefined();
    });
});
