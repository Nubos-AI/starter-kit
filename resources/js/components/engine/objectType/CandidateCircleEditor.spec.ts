import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CandidateCircleEditor from '@/components/engine/objectType/CandidateCircleEditor.vue';
import { multiSelectStubs, selectStubs } from '@/tests/selectStubs';
import type { CandidateCircleValue } from '@/types/approvalDefinitions';
import type { SelectOption } from '@/types/ui';

const roleOptions: SelectOption[] = [
    { value: 'role-lead', label: 'Teamleitung' },
    { value: 'role-manager', label: 'Vertriebsleitung' },
];

const teamOptions: SelectOption[] = [
    { value: 'team-sales', label: 'Vertrieb' },
    { value: 'team-support', label: 'Support' },
];

const userOptions: SelectOption[] = [
    { value: 'user-1', label: 'Rita Rossi', description: 'rita@nubos.de' },
    { value: 'user-2', label: 'Tom Teller', description: 'tom@nubos.de' },
];

const fieldOptions: SelectOption[] = [
    { value: 'owner_id', label: 'Eigentümer' },
];

const SwitchStub = {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" :data-checked="modelValue" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

const stubs = {
    ...selectStubs,
    ...multiSelectStubs,
    Switch: SwitchStub,
};

function emptyCircle(): CandidateCircleValue {
    return {
        sources: [],
        role_ids: [],
        team_ids: [],
        include_record_team: false,
        field_key: null,
        user_ids: [],
    };
}

function mountEditor(
    modelValue: CandidateCircleValue = emptyCircle(),
): VueWrapper {
    return mount(CandidateCircleEditor, {
        props: {
            modelValue,
            roleOptions,
            teamOptions,
            userOptions,
            fieldOptions,
        },
        global: { stubs },
    });
}

function multiSelectAt(wrapper: VueWrapper, index: number) {
    return wrapper.findAll<HTMLSelectElement>('select.ui-multi-select')[index];
}

function fieldSelect(wrapper: VueWrapper) {
    return wrapper.get<HTMLSelectElement>('select.ui-select');
}

function recordTeamSwitch(wrapper: VueWrapper) {
    return wrapper.get('button[data-checked]');
}

function lastEmittedCircle(wrapper: VueWrapper): CandidateCircleValue {
    const events = wrapper.emitted('update:modelValue') as
        | [CandidateCircleValue][]
        | undefined;

    if (events === undefined) {
        throw new Error('no update:modelValue emitted');
    }

    return events[events.length - 1][0];
}

describe('CandidateCircleEditor — nothing is a free text field', () => {
    it('offers the roles source only through a multi select', () => {
        const wrapper = mountEditor();

        expect(wrapper.findAll('input[type="text"]')).toHaveLength(0);
        expect(multiSelectAt(wrapper, 0)).toBeDefined();
    });

    it('offers exactly one field option, the system owner field', () => {
        const wrapper = mountEditor();

        expect(
            fieldSelect(wrapper)
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual(['owner_id']);
    });
});

describe('CandidateCircleEditor — each source is individually operable', () => {
    it('lets the roles source be edited independently', async () => {
        const wrapper = mountEditor();

        await multiSelectAt(wrapper, 0).setValue(['role-lead']);

        const circle = lastEmittedCircle(wrapper);

        expect(circle.role_ids).toEqual(['role-lead']);
        expect(circle.sources).toContain('role');
        expect(circle.team_ids).toEqual([]);
        expect(circle.user_ids).toEqual([]);
        expect(circle.field_key).toBeNull();
        expect(circle.include_record_team).toBe(false);
    });

    it('lets the teams source be edited independently', async () => {
        const wrapper = mountEditor();

        await multiSelectAt(wrapper, 1).setValue(['team-sales']);

        const circle = lastEmittedCircle(wrapper);

        expect(circle.team_ids).toEqual(['team-sales']);
        expect(circle.sources).toContain('team');
        expect(circle.role_ids).toEqual([]);
        expect(circle.user_ids).toEqual([]);
    });

    it('lets the record-team switch be toggled independently', async () => {
        const wrapper = mountEditor();

        await recordTeamSwitch(wrapper).trigger('click');

        const circle = lastEmittedCircle(wrapper);

        expect(circle.include_record_team).toBe(true);
        expect(circle.role_ids).toEqual([]);
        expect(circle.team_ids).toEqual([]);
    });

    it('lets the field source be edited independently', async () => {
        const wrapper = mountEditor();

        await fieldSelect(wrapper).setValue('owner_id');

        const circle = lastEmittedCircle(wrapper);

        expect(circle.field_key).toBe('owner_id');
        expect(circle.sources).toContain('field');
        expect(circle.role_ids).toEqual([]);
        expect(circle.user_ids).toEqual([]);
    });

    it('lets the fixed user list be edited independently', async () => {
        const wrapper = mountEditor();

        await multiSelectAt(wrapper, 2).setValue(['user-1']);

        const circle = lastEmittedCircle(wrapper);

        expect(circle.user_ids).toEqual(['user-1']);
        expect(circle.sources).toContain('fixed_list');
        expect(circle.role_ids).toEqual([]);
        expect(circle.team_ids).toEqual([]);
    });
});

describe('CandidateCircleEditor — the emitted value matches the DTO format', () => {
    it('edits all four candidate sources', async () => {
        const wrapper = mountEditor();

        await multiSelectAt(wrapper, 0).setValue(['role-lead']);
        await multiSelectAt(wrapper, 1).setValue(['team-sales']);
        await recordTeamSwitch(wrapper).trigger('click');
        await fieldSelect(wrapper).setValue('owner_id');
        await multiSelectAt(wrapper, 2).setValue(['user-1']);

        const circle = lastEmittedCircle(wrapper);

        expect(Object.keys(circle).sort()).toEqual(
            [
                'sources',
                'role_ids',
                'team_ids',
                'include_record_team',
                'field_key',
                'user_ids',
            ].sort(),
        );
        expect(circle.sources.slice().sort()).toEqual(
            ['field', 'fixed_list', 'role', 'team'].sort(),
        );
        expect(circle.role_ids).toEqual(['role-lead']);
        expect(circle.team_ids).toEqual(['team-sales']);
        expect(circle.include_record_team).toBe(true);
        expect(circle.field_key).toBe('owner_id');
        expect(circle.user_ids).toEqual(['user-1']);
    });

    it('keeps the DTO empty defaults for every source left untouched', async () => {
        const wrapper = mountEditor();

        await multiSelectAt(wrapper, 0).setValue(['role-manager']);

        const circle = lastEmittedCircle(wrapper);

        expect(circle).toEqual({
            sources: ['role'],
            role_ids: ['role-manager'],
            team_ids: [],
            include_record_team: false,
            field_key: null,
            user_ids: [],
        });
    });

    it('does not emit anything on mount', () => {
        const wrapper = mountEditor();

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
