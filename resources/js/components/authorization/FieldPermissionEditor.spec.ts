import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldPermissionEditor from '@/components/authorization/FieldPermissionEditor.vue';
import type {
    FieldGrantDraft,
    FieldPermissionEntry,
    FieldPermissionGroup,
} from '@/types/fieldPermissions';

const stubs = {
    Select: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template:
            '<select class="dropdown" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
    },
    SelectTrigger: { render: () => null },
    SelectValue: { render: () => null },
    SelectContent: { template: '<slot />' },
    SelectItem: {
        props: ['value'],
        template: '<option :value="value"><slot /></option>',
    },
};

type Wrapper = ReturnType<typeof mount>;

function field(
    overrides: Partial<FieldPermissionEntry> = {},
): FieldPermissionEntry {
    return {
        id: 'field-1',
        key: 'email',
        read: true,
        write: true,
        ...overrides,
    };
}

function group(
    id: string,
    name: string,
    fields: FieldPermissionEntry[],
): FieldPermissionGroup {
    return {
        objectType: { id, key: id, slug: id, name },
        fields,
    };
}

function draftFor(
    groups: FieldPermissionGroup[],
    overrides: Record<string, Record<string, FieldGrantDraft>> = {},
): Record<string, Record<string, FieldGrantDraft>> {
    const draft: Record<string, Record<string, FieldGrantDraft>> = {};

    for (const entry of groups) {
        draft[entry.objectType.id] = Object.fromEntries(
            entry.fields.map((current) => [
                current.id,
                { read: current.read, write: current.write },
            ]),
        );
    }

    for (const [objectTypeId, fields] of Object.entries(overrides)) {
        draft[objectTypeId] = { ...draft[objectTypeId], ...fields };
    }

    return draft;
}

function mountEditor(
    groups: FieldPermissionGroup[],
    extra: Record<string, unknown> = {},
): Wrapper {
    return mount(FieldPermissionEditor, {
        props: {
            groups,
            draft: draftFor(groups),
            isEscalated: false,
            ...extra,
        },
        global: { stubs },
    });
}

function badgeText(wrapper: Wrapper, fieldId: string): string {
    return wrapper.get(`[data-testid="field-state-${fieldId}"]`).text();
}

function isDisabled(wrapper: Wrapper, selector: string): boolean {
    return wrapper.get(selector).attributes('disabled') !== undefined;
}

describe('FieldPermissionEditor — field state', () => {
    it('marks a field the role may see and edit as unrestricted', () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])]);

        expect(badgeText(wrapper, 'field-1')).toBe('Uneingeschränkt');
    });

    it('marks a field the role may not edit as restricted', () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [field({ read: true, write: false })]),
        ]);

        expect(badgeText(wrapper, 'field-1')).toBe('Eingeschränkt');
    });

    it('follows the draft rather than the saved state', () => {
        const groups = [group('obj-1', 'Kontakt', [field()])];
        const wrapper = mountEditor(groups, {
            draft: draftFor(groups, {
                'obj-1': { 'field-1': { read: false, write: false } },
            }),
        });

        expect(badgeText(wrapper, 'field-1')).toBe('Eingeschränkt');
    });

    it('explains that a restriction binds only this role', () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])]);

        expect(wrapper.text()).toContain(
            'gilt die Einschränkung nur für diese Rolle',
        );
    });

    it('labels the two rights as seeing and editing', () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])]);

        expect(wrapper.get('label[for="field-read-field-1"]').text()).toBe(
            'Sehen',
        );
        expect(wrapper.get('label[for="field-write-field-1"]').text()).toBe(
            'Bearbeiten',
        );
    });

    it('counts the restricted fields of the selected object type', () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [
                field({
                    id: 'field-1',
                    key: 'email',
                    read: false,
                    write: false,
                }),
                field({ id: 'field-2', key: 'phone' }),
                field({ id: 'field-3', key: 'salary' }),
            ]),
        ]);

        expect(wrapper.text()).toContain('1 von 3 Feldern eingeschränkt');
    });

    it('reports an object type without fields as empty', () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [])]);

        expect(wrapper.text()).toContain(
            'Für diesen Objekttyp sind keine Felder definiert.',
        );
        expect(wrapper.findAll('li')).toHaveLength(0);
    });
});

describe('FieldPermissionEditor — object type selection', () => {
    it('shows the first object type by default and offers all of them', () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [field({ id: 'field-1', key: 'email' })]),
            group('obj-2', 'Firma', [field({ id: 'field-2', key: 'umsatz' })]),
        ]);

        expect(wrapper.get('select.dropdown').text()).toContain('Kontakt');
        expect(wrapper.get('select.dropdown').text()).toContain('Firma');
        expect(wrapper.find('#field-read-field-1').exists()).toBe(true);
        expect(wrapper.find('#field-read-field-2').exists()).toBe(false);
    });

    it('switches the shown fields and the counter when the object type changes', async () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [field({ id: 'field-1', key: 'email' })]),
            group('obj-2', 'Firma', [
                field({ id: 'field-2', key: 'umsatz', write: false }),
                field({ id: 'field-3', key: 'notiz' }),
            ]),
        ]);

        await wrapper.get('select.dropdown').setValue('obj-2');

        expect(wrapper.find('#field-read-field-1').exists()).toBe(false);
        expect(wrapper.find('#field-read-field-2').exists()).toBe(true);
        expect(wrapper.text()).toContain('1 von 2 Feldern eingeschränkt');
    });
});

describe('FieldPermissionEditor — interaction', () => {
    it('takes editing away together with seeing', async () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])]);

        await wrapper.get('#field-read-field-1').trigger('click');

        expect(wrapper.emitted('update:grant')).toHaveLength(1);
        expect(wrapper.emitted('update:grant')![0]).toEqual([
            {
                objectTypeId: 'obj-1',
                fieldId: 'field-1',
                read: false,
                write: false,
            },
        ]);
    });

    it('takes editing away alone and keeps seeing', async () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])]);

        await wrapper.get('#field-write-field-1').trigger('click');

        expect(wrapper.emitted('update:grant')![0]).toEqual([
            {
                objectTypeId: 'obj-1',
                fieldId: 'field-1',
                read: true,
                write: false,
            },
        ]);
    });

    it('gives seeing back without editing', async () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [field({ read: false, write: false })]),
        ]);

        await wrapper.get('#field-read-field-1').trigger('click');

        expect(wrapper.emitted('update:grant')![0]).toEqual([
            {
                objectTypeId: 'obj-1',
                fieldId: 'field-1',
                read: true,
                write: false,
            },
        ]);
    });

    it('locks editing while the role may not see the field', () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [
                field({
                    id: 'field-1',
                    key: 'email',
                    read: false,
                    write: false,
                }),
                field({ id: 'field-2', key: 'phone' }),
            ]),
        ]);

        expect(isDisabled(wrapper, '#field-write-field-1')).toBe(true);
        expect(isDisabled(wrapper, '#field-write-field-2')).toBe(false);
    });

    it('emits for the object type selected at that moment', async () => {
        const wrapper = mountEditor([
            group('obj-1', 'Kontakt', [field({ id: 'field-1', key: 'email' })]),
            group('obj-2', 'Firma', [field({ id: 'field-2', key: 'umsatz' })]),
        ]);

        await wrapper.get('select.dropdown').setValue('obj-2');
        await wrapper.get('#field-write-field-2').trigger('click');

        expect(wrapper.emitted('update:grant')![0]).toEqual([
            {
                objectTypeId: 'obj-2',
                fieldId: 'field-2',
                read: true,
                write: false,
            },
        ]);
    });
});

describe('FieldPermissionEditor — superior authority', () => {
    it('explains that field rights have no effect and blocks every change', async () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])], {
            isEscalated: true,
        });

        expect(wrapper.text()).toContain('Feldrechte greifen hier nicht');

        await wrapper.get('#field-read-field-1').trigger('click');

        expect(wrapper.emitted('update:grant')).toBeUndefined();
        expect(isDisabled(wrapper, '#field-write-field-1')).toBe(true);
    });

    it('drops the role-only hint for an escalated role', () => {
        const wrapper = mountEditor([group('obj-1', 'Kontakt', [field()])], {
            isEscalated: true,
        });

        expect(wrapper.text()).not.toContain(
            'gilt die Einschränkung nur für diese Rolle',
        );
    });
});
