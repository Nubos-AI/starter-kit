import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DynamicForm from '@/components/DynamicForm.vue';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';

function field(
    key: string,
    fieldGroupId: string | null = null,
    description: string | null = null,
): FieldDefinition {
    return {
        key,
        field_group_id: fieldGroupId,
        field_type: 'text_short',
        label: key,
        description,
        is_required: false,
    };
}

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

function mountForm(
    fields: FieldDefinition[],
    groups: FieldGroupRow[] = [],
    attachToBody = false,
): ReturnType<typeof mount> {
    return mount(DynamicForm, {
        props: { fields, groups, modelValue: {} },
        ...(attachToBody ? { attachTo: document.body } : {}),
    });
}

function sections(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper
        .findAll('[data-field-section]')
        .map((section) => section.attributes('data-field-section') ?? '');
}

function sectionTitles(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper
        .findAll('[data-field-section-toggle]')
        .map((toggle) => toggle.text());
}

describe('DynamicForm — grouped sections', () => {
    it('renders one section titled "Daten" when no group is given', () => {
        const wrapper = mountForm([field('name'), field('note')]);

        expect(sections(wrapper)).toEqual(['group:ungrouped']);
        expect(sectionTitles(wrapper)).toEqual(['Daten']);
        expect(wrapper.findAll('input')).toHaveLength(2);
    });

    it('opens a labelled section per group, ungrouped fields first', () => {
        const wrapper = mountForm(
            [
                field('name'),
                field('street', address.id),
                field('email', contact.id),
            ],
            [address, contact],
        );

        expect(sections(wrapper)).toEqual([
            'group:ungrouped',
            'group:fg-contact',
            'group:fg-address',
        ]);
        expect(sectionTitles(wrapper)).toEqual(['Daten', 'Kontakt', 'Adresse']);
    });

    it('leaves out a group that holds no field of this record', () => {
        const wrapper = mountForm([field('name')], [address]);

        expect(sections(wrapper)).toEqual(['group:ungrouped']);
    });

    it('drops the ungrouped section when every field carries a group', () => {
        const wrapper = mountForm([field('street', address.id)], [address]);

        expect(sections(wrapper)).toEqual(['group:fg-address']);
        expect(sectionTitles(wrapper)).toEqual(['Adresse']);
    });

    it('keeps every field rendered exactly once across the sections', () => {
        const wrapper = mountForm(
            [field('name'), field('street', address.id)],
            [address],
        );

        expect(wrapper.findAll('input')).toHaveLength(2);
    });
});

describe('DynamicForm — panels the user switched off', () => {
    it('leaves a hidden section out of the form', () => {
        const wrapper = mount(DynamicForm, {
            props: {
                fields: [field('name'), field('street', address.id)],
                groups: [address],
                modelValue: {},
                hiddenSections: ['group:fg-address'],
            },
        });

        expect(sections(wrapper)).toEqual(['group:ungrouped']);
        expect(wrapper.findAll('input')).toHaveLength(1);
    });

    it('renders every section when nothing is hidden', () => {
        const wrapper = mount(DynamicForm, {
            props: {
                fields: [field('name'), field('street', address.id)],
                groups: [address],
                modelValue: {},
                hiddenSections: [],
            },
        });

        expect(sections(wrapper)).toEqual([
            'group:ungrouped',
            'group:fg-address',
        ]);
    });
});

describe('DynamicForm — descriptions guide the input', () => {
    it('renders the field description as help text tied to the input', () => {
        const wrapper = mountForm([
            field('street', null, 'Straße und Hausnummer.'),
        ]);

        expect(wrapper.text()).toContain('Straße und Hausnummer.');
        expect(wrapper.get('#street-help').text()).toBe(
            'Straße und Hausnummer.',
        );
        expect(wrapper.get('input').attributes('aria-describedby')).toBe(
            'street-help',
        );
    });

    it('renders no help paragraph for a field without a description', () => {
        const wrapper = mountForm([field('street')]);

        expect(wrapper.find('#street-help').exists()).toBe(false);
    });

    it('renders the group description under its heading', () => {
        const wrapper = mountForm(
            [field('street', address.id)],
            [{ ...address, description: 'Die Rechnungsanschrift.' }],
        );
        const section = wrapper.get('[data-field-section="group:fg-address"]');

        expect(section.get('h3').text()).toBe('Adresse');
        expect(section.text()).toContain('Die Rechnungsanschrift.');
    });

    it('renders the heading alone for a group without a description', () => {
        const wrapper = mountForm([field('street', address.id)], [address]);
        const section = wrapper.get('[data-field-section="group:fg-address"]');

        expect(section.findAll('p')).toHaveLength(0);
    });
});

describe('DynamicForm — collapsible sections', () => {
    it('hides the fields of a section once its toggle is pressed', async () => {
        const wrapper = mountForm(
            [field('name'), field('street', address.id)],
            [address],
        );

        await wrapper
            .get('[data-field-section-toggle="group:fg-address"]')
            .trigger('click');

        expect(wrapper.findAll('input')).toHaveLength(1);
        expect(wrapper.find('#name').exists()).toBe(true);
        expect(wrapper.find('#street').exists()).toBe(false);
    });

    it('brings the fields back on the next press', async () => {
        const wrapper = mountForm([field('street', address.id)], [address]);
        const toggle = wrapper.get(
            '[data-field-section-toggle="group:fg-address"]',
        );

        await toggle.trigger('click');
        await toggle.trigger('click');

        expect(wrapper.find('#street').exists()).toBe(true);
    });

    it('collapses one section without touching the others', async () => {
        const wrapper = mountForm(
            [field('name'), field('street', address.id)],
            [address],
        );

        await wrapper
            .get('[data-field-section-toggle="group:ungrouped"]')
            .trigger('click');

        expect(wrapper.find('#name').exists()).toBe(false);
        expect(wrapper.find('#street').exists()).toBe(true);
    });
});

describe('DynamicForm — description behind an info icon', () => {
    it('renders an info trigger next to the label of a described field', () => {
        const wrapper = mountForm([
            field('street', null, 'Straße und Hausnummer.'),
        ]);

        expect(
            wrapper.find('[data-field-help-trigger="street"]').exists(),
        ).toBe(true);
    });

    it('renders no info trigger for a field without a description', () => {
        const wrapper = mountForm([field('street')]);

        expect(
            wrapper.find('[data-field-help-trigger="street"]').exists(),
        ).toBe(false);
    });

    it('reveals the description in a tooltip on click and hides it again', async () => {
        document.body.replaceChildren();

        const wrapper = mountForm(
            [field('street', null, 'Straße und Hausnummer.')],
            [],
            true,
        );
        const trigger = wrapper.get('[data-field-help-trigger="street"]');

        expect(
            document.querySelector('[data-slot="tooltip-content"]'),
        ).toBeNull();

        await trigger.trigger('click');

        const content = document.querySelector('[data-slot="tooltip-content"]');
        expect(content).not.toBeNull();
        expect(content?.textContent).toContain('Straße und Hausnummer.');

        await trigger.trigger('click');

        expect(
            document.querySelector('[data-slot="tooltip-content"]'),
        ).toBeNull();

        wrapper.unmount();
        document.body.replaceChildren();
    });
});

describe('DynamicForm — the tooltip closes itself again', () => {
    function tooltip(): Element | null {
        return document.querySelector('[data-slot="tooltip-content"]');
    }

    beforeEach(() => {
        document.body.replaceChildren();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.replaceChildren();
    });

    it('hides the description three seconds after it was opened', async () => {
        const wrapper = mountForm(
            [field('street', null, 'Straße und Hausnummer.')],
            [],
            true,
        );

        await wrapper
            .get('[data-field-help-trigger="street"]')
            .trigger('click');
        expect(tooltip()).not.toBeNull();

        vi.advanceTimersByTime(2999);
        await flushPromises();
        expect(tooltip()).not.toBeNull();

        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(tooltip()).toBeNull();

        wrapper.unmount();
    });

    it('starts the three seconds over when the icon is clicked again', async () => {
        const wrapper = mountForm(
            [field('street', null, 'Straße und Hausnummer.')],
            [],
            true,
        );
        const trigger = wrapper.get('[data-field-help-trigger="street"]');

        await trigger.trigger('click');
        vi.advanceTimersByTime(2000);
        await flushPromises();

        await trigger.trigger('click');
        expect(tooltip()).toBeNull();

        await trigger.trigger('click');
        vi.advanceTimersByTime(2000);
        await flushPromises();
        expect(tooltip()).not.toBeNull();

        vi.advanceTimersByTime(1000);
        await flushPromises();
        expect(tooltip()).toBeNull();

        wrapper.unmount();
    });

    it('lets no pending timer close a tooltip that was reopened', async () => {
        const wrapper = mountForm(
            [field('street', null, 'Straße und Hausnummer.')],
            [],
            true,
        );
        const trigger = wrapper.get('[data-field-help-trigger="street"]');

        await trigger.trigger('click');
        await trigger.trigger('click');
        vi.advanceTimersByTime(2500);
        await flushPromises();

        await trigger.trigger('click');
        vi.advanceTimersByTime(600);
        await flushPromises();

        expect(tooltip()).not.toBeNull();

        wrapper.unmount();
    });
});

describe('DynamicForm computed fields', () => {
    it('renders a computed field as a read-only value', () => {
        const computed = {
            ...field('display_name'),
            field_type: 'computed',
        } as FieldDefinition;

        const wrapper = mountForm([computed]);

        expect(wrapper.text()).toContain('display_name');
    });
});
