import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AgingRules from '@/pages/objectTypes/edit/AgingRules.vue';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        url: '/engine/object-types/departments/edit/aging-rules',
        props: {
            auth: {
                user: null,
                can: { 'object-types.update': true } as Record<string, boolean>,
                authority: null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    usePage: () => pageState,
}));

const AgingCardStub = {
    name: 'AgingCardStub',
    props: [
        'objectTypeSlug',
        'rules',
        'clockFieldOptions',
        'conditionFields',
        'activeRuleLimit',
        'canCreate',
    ],
    template: '<div class="aging-card-stub" />',
};

const agingRules = [
    {
        id: '01AGINGRULE00000000000001',
        object_type_id: 'ot-1',
        name: 'Liegengebliebene Vorgänge',
        clock: 'updated_at',
        clock_field_key: null,
        condition: [],
        thresholds: [{ after_days: 7, color: 'amber' }],
        is_active: true,
        triggers_automation: false,
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-20T10:00:00+00:00',
    },
];

const agingClockFieldOptions = [{ value: 'due_on', label: 'Fällig am' }];

const agingConditionFields = [
    {
        key: 'stage',
        field_type: 'single_select',
        label: 'Phase',
        is_required: false,
    },
];

function objectType(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id: 'ot-1',
        key: 'departments',
        slug: 'departments',
        name: 'Departments',
        business_key_prefix: 'DP',
        record_number_format: '##########',
        business_key_locked: false,
        is_system: false,
        storage_strategy: 'generic',
        hierarchy_relationship_type_id: null,
        ...overrides,
    };
}

function mountAgingRules(payload: Record<string, unknown> = objectType()) {
    return mount(AgingRules, {
        props: {
            objectType: payload as never,
            agingRules: agingRules as never,
            agingClockFieldOptions,
            agingConditionFields: agingConditionFields as never,
            agingActiveRuleLimit: 20,
        },
        global: { stubs: { ObjectTypeAgingCard: AgingCardStub } },
    });
}

describe('objectTypes/edit/AgingRules', () => {
    beforeEach(() => {
        pageState.props.auth.can = { 'object-types.update': true };
    });

    it('hands the rules, the clock options, the condition fields and the limit down', () => {
        const card = mountAgingRules().findComponent(AgingCardStub);

        expect(card.props('objectTypeSlug')).toBe('departments');
        expect(card.props('rules')).toEqual(agingRules);
        expect(card.props('clockFieldOptions')).toEqual(agingClockFieldOptions);
        expect(card.props('conditionFields')).toEqual(agingConditionFields);
        expect(card.props('activeRuleLimit')).toBe(20);
        expect(card.props('canCreate')).toBe(true);
    });

    it('keeps creating rules open on a system object type, since aging is not a schema change', () => {
        const card = mountAgingRules(
            objectType({ is_system: true }),
        ).findComponent(AgingCardStub);

        expect(card.props('canCreate')).toBe(true);
    });

    it('withholds creating rules without the update permission', () => {
        pageState.props.auth.can = {};

        const card = mountAgingRules().findComponent(AgingCardStub);

        expect(card.props('canCreate')).toBe(false);
    });
});
