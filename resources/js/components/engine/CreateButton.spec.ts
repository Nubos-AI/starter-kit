import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CreateButton from '@/components/engine/CreateButton.vue';
import type { RecordObjectType } from '@/types/records';

const DropdownMenuItemStub = {
    emits: ['select'],
    template:
        '<button type="button" class="ddi" @click="$emit(\'select\')"><slot /></button>',
};

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    DropdownMenu: passthrough,
    DropdownMenuTrigger: passthrough,
    DropdownMenuContent: passthrough,
    DropdownMenuGroup: passthrough,
    DropdownMenuItem: DropdownMenuItemStub,
};

function objectType(id: string, name: string): RecordObjectType {
    return {
        id,
        key: id,
        slug: id,
        name,
        requiresDeletionReason: false,
        hasHierarchy: false,
    };
}

const deal = objectType('deal', 'Deal');
const contact = objectType('contact', 'Kontakt');

describe('CreateButton — single object type (SC-9)', () => {
    it('renders a direct create button and emits create with the type', async () => {
        const wrapper = mount(CreateButton, {
            props: { objectType: deal },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Deal anlegen');
        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('create')).toHaveLength(1);
        expect(wrapper.emitted('create')![0]).toEqual([deal]);
    });
});

describe('CreateButton — multiple object types (SC-9)', () => {
    it('renders a dropdown offering each creatable type', () => {
        const wrapper = mount(CreateButton, {
            props: { objectType: deal, creatableTypes: [deal, contact] },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Anlegen');
        expect(wrapper.text()).toContain('Deal anlegen');
        expect(wrapper.text()).toContain('Kontakt anlegen');
    });

    it('emits create with the specific type chosen from the dropdown', async () => {
        const wrapper = mount(CreateButton, {
            props: { objectType: deal, creatableTypes: [deal, contact] },
            global: { stubs },
        });

        const items = wrapper.findAll('.ddi');
        expect(items).toHaveLength(2);

        await items[1].trigger('click');
        expect(wrapper.emitted('create')![0]).toEqual([contact]);
    });
});
