import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import AgingRuleForm from '@/components/engine/objectType/AgingRuleForm.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { selectStubs } from '@/tests/selectStubs';
import type {
    AgingRulePayload,
    AgingRuleRow,
    AgingThreshold,
} from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';
import { setUrlDefaults } from '@/wayfinder';

const ruleFormInertia = vi.hoisted(() => ({
    visit: vi.fn(),
    on: vi.fn(() => vi.fn()),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: ruleFormInertia.on,
        visit: ruleFormInertia.visit,
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    usePage: () => ({
        url: '/personal/engine/object-types/deals/edit',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

const CONDITION_EMPTY_HINT =
    'Ohne Bedingung gilt die Regel für alle Datensätze dieses Objekttyps.';

const CLOCK_FIELD_EMPTY_HINT =
    'Dieser Objekttyp hat kein Datumsfeld, das sich messen lässt.';

const ACTIVE_LIMIT_HINT =
    'Dieser Objekttyp hat bereits die höchste unterstützte Zahl aktiver Regeln (20). Deaktivieren Sie zuerst eine andere Regel.';

const RULE_ACTIVE_LIMIT = 20;

const ruleClockFieldOptions: SelectOption[] = [
    { value: 'due_on', label: 'Fällig am' },
    { value: 'signed_at', label: 'Unterschrieben am' },
];

const ruleConditionFields: FieldDefinition[] = [
    {
        key: 'stage',
        field_type: 'single_select',
        label: 'Phase',
        is_required: false,
    },
];

const ruleConditionTree: FilterGroupNode = {
    combinator: 'and',
    conditions: [{ field: 'stage', operator: 'equals', value: 'open' }],
};

const ruleThresholds: AgingThreshold[] = [
    { after_days: 7, color: 'amber' },
    { after_days: 30, color: 'red' },
];

function agingRule(overrides: Partial<AgingRuleRow> = {}): AgingRuleRow {
    return {
        id: '01AGINGRULE00000000000001',
        object_type_id: '01OBJECTTYPE000000000001',
        name: 'Liegengebliebene Vorgänge',
        clock: 'updated_at',
        clock_field_key: null,
        condition: [],
        thresholds: ruleThresholds.map((threshold) => ({ ...threshold })),
        is_active: true,
        triggers_automation: false,
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-20T10:00:00+00:00',
        ...overrides,
    };
}

const ruleFormPassthrough = { template: '<div><slot /></div>' };

const RuleSheetStub = {
    name: 'RuleSheetStub',
    props: ['open'],
    template: '<div><slot /></div>',
};

const RuleFilterBuilderStub = {
    name: 'RuleFilterBuilderStub',
    props: ['fields', 'modelValue', 'showActions'],
    emits: ['update:modelValue'],
    setup(
        props: { modelValue?: FilterGroupNode },
        { emit }: { emit: (event: string, tree: FilterGroupNode) => void },
    ) {
        emit(
            'update:modelValue',
            props.modelValue ?? { combinator: 'and', conditions: [] },
        );
    },
    template: '<div data-aging-filter-builder />',
};

const ruleFormStubs = {
    ...selectStubs,
    Sheet: RuleSheetStub,
    SheetContent: ruleFormPassthrough,
    SheetHeader: ruleFormPassthrough,
    SheetFooter: ruleFormPassthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
    Dialog: ruleFormPassthrough,
    DialogContent: ruleFormPassthrough,
    DialogHeader: ruleFormPassthrough,
    DialogFooter: ruleFormPassthrough,
    DialogTitle: ruleFormPassthrough,
    DialogDescription: ruleFormPassthrough,
    FilterBuilder: RuleFilterBuilderStub,
};

interface RuleFormOptions {
    rule?: AgingRuleRow | null;
    clockFieldOptions?: SelectOption[];
    conditionFields?: FieldDefinition[];
    activeRuleCount?: number;
    activeRuleLimit?: number;
    errors?: Record<string, string>;
    processing?: boolean;
}

function mountRuleForm(options: RuleFormOptions = {}): VueWrapper {
    return mount(AgingRuleForm, {
        props: {
            rule: options.rule ?? null,
            clockFieldOptions:
                options.clockFieldOptions ?? ruleClockFieldOptions,
            conditionFields: options.conditionFields ?? ruleConditionFields,
            activeRuleCount: options.activeRuleCount ?? 0,
            activeRuleLimit: options.activeRuleLimit ?? RULE_ACTIVE_LIMIT,
            errors: options.errors ?? {},
            processing: options.processing ?? false,
        },
        global: { stubs: ruleFormStubs },
    });
}

function clockSelect(
    wrapper: VueWrapper,
): Omit<DOMWrapper<HTMLSelectElement>, 'exists'> {
    return wrapper.get<HTMLSelectElement>('select[name="clock"]');
}

async function chooseClock(wrapper: VueWrapper, clock: string): Promise<void> {
    await clockSelect(wrapper).setValue(clock);
    await nextTick();
}

async function typeName(wrapper: VueWrapper, value: string): Promise<void> {
    await wrapper.get('[name="name"]').setValue(value);
    await nextTick();
}

async function saveRule(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-form-save]').trigger('click');
    await nextTick();
}

function submittedRule(wrapper: VueWrapper): AgingRulePayload {
    const emitted = wrapper.emitted('submit');

    if (emitted === undefined) {
        throw new Error('the rule form never submitted');
    }

    return emitted[emitted.length - 1][0] as AgingRulePayload;
}

function isSaveDisabled(wrapper: VueWrapper): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') !== undefined;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'personal' });
    ruleFormInertia.visit.mockReset();
});

describe('AgingRuleForm — the clock and its date field', () => {
    it('shows the date field only for the free date field clock, in both directions', async () => {
        const wrapper = mountRuleForm();

        expect(wrapper.find('[data-aging-clock-field]').exists()).toBe(false);

        await chooseClock(wrapper, 'field');

        expect(wrapper.find('[data-aging-clock-field]').exists()).toBe(true);
        expect(wrapper.find('select[name="clock_field_key"]').exists()).toBe(
            true,
        );

        await chooseClock(wrapper, 'updated_at');

        expect(wrapper.find('[data-aging-clock-field]').exists()).toBe(false);
        expect(wrapper.find('select[name="clock_field_key"]').exists()).toBe(
            false,
        );
    });

    it('sends a null date field key after switching away from the free date field', async () => {
        const wrapper = mountRuleForm({
            rule: agingRule({ clock: 'field', clock_field_key: 'due_on' }),
        });

        expect(wrapper.find('[data-aging-clock-field]').exists()).toBe(true);

        await chooseClock(wrapper, 'updated_at');
        await saveRule(wrapper);

        const payload = submittedRule(wrapper);

        expect(payload.clock).toBe('updated_at');
        expect(payload.clock_field_key).toBeNull();
    });

    it('keeps the free date field clock selectable without a usable date field and refuses to save', async () => {
        const wrapper = mountRuleForm({ clockFieldOptions: [] });

        const fieldOption = clockSelect(wrapper)
            .findAll('option')
            .find((option) => option.attributes('value') === 'field');

        expect(fieldOption).toBeDefined();
        expect(fieldOption?.attributes('disabled')).toBeUndefined();

        await typeName(wrapper, 'Ohne Datumsfeld');
        await chooseClock(wrapper, 'field');

        expect(wrapper.get('[data-aging-clock-field-empty]').text()).toBe(
            CLOCK_FIELD_EMPTY_HINT,
        );

        await saveRule(wrapper);

        expect(wrapper.emitted('submit')).toBeUndefined();
    });
});

describe('AgingRuleForm — the condition', () => {
    it('explains an empty condition and submits it as null', async () => {
        const wrapper = mountRuleForm();

        expect(wrapper.get('[data-aging-condition-empty]').text()).toBe(
            CONDITION_EMPTY_HINT,
        );

        await typeName(wrapper, 'Ohne Bedingung');
        await saveRule(wrapper);

        const payload = submittedRule(wrapper);

        expect(payload.condition).toBeNull();
        expect(payload.thresholds.length).toBeGreaterThanOrEqual(1);
    });

    it('enables saving when only the condition builder changed', async () => {
        const wrapper = mountRuleForm();

        expect(isSaveDisabled(wrapper)).toBe(true);

        wrapper
            .findComponent(RuleFilterBuilderStub)
            .vm.$emit('update:modelValue', ruleConditionTree);
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(false);
        expect(wrapper.find('[data-aging-condition-empty]').exists()).toBe(
            false,
        );

        await saveRule(wrapper);

        expect(submittedRule(wrapper).condition).toEqual(ruleConditionTree);
    });
});

describe('AgingRuleForm — the switches and the thresholds', () => {
    it('keeps saving disabled right after mounting', () => {
        expect(isSaveDisabled(mountRuleForm())).toBe(true);
        expect(isSaveDisabled(mountRuleForm({ rule: agingRule() }))).toBe(true);
    });

    it('omits the automation control without its UI extension', () => {
        const wrapper = mountRuleForm();
        expect(wrapper.find('[data-aging-automation-toggle]').exists()).toBe(
            false,
        );
    });

    it('carries both switches as untouched booleans into the payload', async () => {
        const wrapper = mountRuleForm({
            rule: agingRule({ is_active: true, triggers_automation: false }),
            activeRuleCount: 1,
        });

        await typeName(wrapper, 'Nur der Name ändert sich');
        await saveRule(wrapper);

        const payload = submittedRule(wrapper);

        expect(payload.is_active).toBe(true);
        expect(typeof payload.is_active).toBe('boolean');
        expect(payload.triggers_automation).toBe(false);
        expect(typeof payload.triggers_automation).toBe('boolean');
        expect(payload.name).toBe('Nur der Name ändert sich');
    });

    it('submits descending thresholds in ascending order', async () => {
        const wrapper = mountRuleForm({
            rule: agingRule({
                thresholds: [
                    { after_days: 30, color: 'red' },
                    { after_days: 7, color: 'amber' },
                ],
            }),
        });

        await typeName(wrapper, 'Absteigend eingegeben');
        await saveRule(wrapper);

        expect(submittedRule(wrapper).thresholds).toEqual([
            { after_days: 7, color: 'amber' },
            { after_days: 30, color: 'red' },
        ]);
    });

    it('refuses to submit while two thresholds share a duration', async () => {
        const wrapper = mountRuleForm({
            rule: agingRule({
                thresholds: [
                    { after_days: 7, color: 'amber' },
                    { after_days: 7, color: 'red' },
                ],
            }),
        });

        await typeName(wrapper, 'Doppelte Dauer');

        expect(wrapper.find('[data-aging-threshold-duplicate]').exists()).toBe(
            true,
        );

        await saveRule(wrapper);

        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('disables the active switch at the limit unless the rule is already active', () => {
        const blocked = mountRuleForm({
            activeRuleCount: RULE_ACTIVE_LIMIT,
            activeRuleLimit: RULE_ACTIVE_LIMIT,
        });

        expect(
            blocked.get('[data-aging-active-toggle]').attributes('disabled'),
        ).toBeDefined();
        expect(
            blocked
                .get('[data-aging-active-toggle]')
                .attributes('aria-checked'),
        ).toBe('false');
        expect(blocked.get('[data-aging-active-limit]').text()).toBe(
            ACTIVE_LIMIT_HINT,
        );

        const alreadyActive = mountRuleForm({
            rule: agingRule({ is_active: true }),
            activeRuleCount: RULE_ACTIVE_LIMIT,
            activeRuleLimit: RULE_ACTIVE_LIMIT,
        });

        expect(
            alreadyActive
                .get('[data-aging-active-toggle]')
                .attributes('disabled'),
        ).toBeUndefined();
        expect(alreadyActive.find('[data-aging-active-limit]').exists()).toBe(
            false,
        );
    });

    it('still saves a new rule at the limit by submitting it inactive', async () => {
        const wrapper = mountRuleForm({
            activeRuleCount: RULE_ACTIVE_LIMIT,
            activeRuleLimit: RULE_ACTIVE_LIMIT,
        });

        await typeName(wrapper, 'Eine Regel über der Grenze');

        expect(isSaveDisabled(wrapper)).toBe(false);

        await saveRule(wrapper);

        const payload = submittedRule(wrapper);

        expect(payload.is_active).toBe(false);
        expect(typeof payload.is_active).toBe('boolean');
        expect(payload.name).toBe('Eine Regel über der Grenze');
    });

    it('renders a bound message for every key the write path can reject', () => {
        const wrapper = mountRuleForm({
            rule: agingRule({ clock: 'field', clock_field_key: 'due_on' }),
            errors: {
                name: 'FEHLER_NAME',
                clock_field_key: 'FEHLER_DATUMSFELD',
                condition: 'FEHLER_BEDINGUNG',
                thresholds: 'FEHLER_SCHWELLEN',
                is_active: 'FEHLER_AKTIV',
            },
        });

        const text = wrapper.text();

        expect(text).toContain('FEHLER_NAME');
        expect(text).toContain('FEHLER_DATUMSFELD');
        expect(text).toContain('FEHLER_BEDINGUNG');
        expect(text).toContain('FEHLER_SCHWELLEN');
        expect(text).toContain('FEHLER_AKTIV');
    });
});

describe('AgingRuleForm — leaving the sheet', () => {
    it('asks before dropping pending changes, closing neither the sheet nor the page', async () => {
        const wrapper = mountRuleForm({ rule: agingRule() });

        await typeName(wrapper, 'Angefangen und nicht gespeichert');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            true,
        );
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(ruleFormInertia.visit).not.toHaveBeenCalled();

        wrapper.findComponent(UnsavedChangesDialog).vm.$emit('confirm');
        await nextTick();

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(ruleFormInertia.visit).not.toHaveBeenCalled();

        const untouched = mountRuleForm({ rule: agingRule() });

        await untouched.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(untouched.emitted('close')).toHaveLength(1);
        expect(
            untouched.findComponent(UnsavedChangesDialog).props('open'),
        ).toBe(false);
        expect(ruleFormInertia.visit).not.toHaveBeenCalled();
    });

    it('routes escape and backdrop through the same guarded path', async () => {
        const wrapper = mountRuleForm({ rule: agingRule() });

        await typeName(wrapper, 'Auch hier ungespeichert');

        wrapper.findComponent(RuleSheetStub).vm.$emit('update:open', false);
        await nextTick();

        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            true,
        );
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(ruleFormInertia.visit).not.toHaveBeenCalled();

        wrapper.findComponent(UnsavedChangesDialog).vm.$emit('confirm');
        await nextTick();

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
