import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RelationFieldEditor from '@/components/engine/objectType/RelationFieldEditor.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldType } from '@/types/fields';
import type { RollupTargetOption } from '@/types/rollups';

const relationshipSelector = 'select[name="config[relationship_type_id]"]';
const regionSelector = '[data-testid="relation-config"]';

const relationshipTypes: RollupTargetOption[] = [
    {
        value: 'rel-1',
        label: 'Projekte → Companies',
        target_object_type_id: 'ot-companies',
        has_hierarchy: false,
        fields: [],
    },
    {
        value: 'rel-2',
        label: 'Projekte → Kontakte',
        target_object_type_id: 'ot-contacts',
        has_hierarchy: false,
        fields: [],
    },
];

function mountEditor(
    fieldType: FieldType,
    initialRelationshipTypeId = '',
): ReturnType<typeof mount> {
    return mount(RelationFieldEditor, {
        props: {
            fieldType,
            relationshipTypes,
            initialRelationshipTypeId,
        },
        global: { stubs: { ...selectStubs } },
    });
}

describe('RelationFieldEditor', () => {
    it('offers a relationship type for both relationship field types', () => {
        for (const fieldType of [
            'relation_has_many',
            'relation_many_to_many',
        ] as FieldType[]) {
            const wrapper = mountEditor(fieldType);

            expect(wrapper.find(regionSelector).exists()).toBe(true);
            expect(
                wrapper.findAll(`${relationshipSelector} option`),
            ).toHaveLength(relationshipTypes.length);
        }
    });

    it('stays hidden for every other field type', () => {
        const wrapper = mountEditor('text_short');

        expect(wrapper.find(regionSelector).exists()).toBe(false);
    });

    it('submits the chosen relationship type as the field configuration', async () => {
        const wrapper = mountEditor('relation_many_to_many');

        await wrapper.find(relationshipSelector).setValue('rel-2');

        expect(
            (wrapper.find(relationshipSelector).element as HTMLSelectElement)
                .value,
        ).toBe('rel-2');
    });

    it('prefills the relationship type of an existing field', () => {
        const wrapper = mountEditor('relation_has_many', 'rel-1');

        expect(
            (wrapper.find(relationshipSelector).element as HTMLSelectElement)
                .value,
        ).toBe('rel-1');
    });

    it('explains the missing prerequisite when no relationship type exists', () => {
        const wrapper = mount(RelationFieldEditor, {
            props: {
                fieldType: 'relation_has_many' as FieldType,
                relationshipTypes: [],
                initialRelationshipTypeId: '',
            },
            global: { stubs: { ...selectStubs } },
        });

        expect(wrapper.text()).toContain('noch kein Beziehungstyp angelegt');
    });
});
