import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import GoalPeriodHistory from '@/components/goals/GoalPeriodHistory.vue';
import GoalProgressCard from '@/components/goals/GoalProgressCard.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Form from '@/pages/goals/Form.vue';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';
import type { GoalListRow, GoalPeriod } from '@/types/goals';
import { resolveGoalOptionRefusal } from '@/types/goals';
import type { SelectOption } from '@/types/ui';

interface FormState {
    errors: Record<string, string>;
    processing: boolean;
}

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    transform: {
        apply: null as
            | null
            | ((data: Record<string, unknown>) => Record<string, unknown>),
    },
    form: null as null | FormState,
    page: {
        url: '/nubos/goals/create',
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => {
    const state = reactive({
        errors: {} as Record<string, string>,
        processing: false,
        transform(
            fn: (data: Record<string, unknown>) => Record<string, unknown>,
        ) {
            inertia.transform.apply = fn;

            return state;
        },
        post: inertia.post,
        put: inertia.put,
    });

    inertia.form = state;

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial: Record<string, unknown>) =>
            Object.assign(state, initial),
        router: {
            on: vi.fn(() => vi.fn()),
            visit: inertia.visit,
            post: inertia.post,
        },
        usePage: () => inertia.page,
    };
});

const NOW = '2026-08-11T12:00:00.000Z';

const GOAL_ID = '01GOAL00000000000000001A';

const DATED_REPORT = '01REPORT000000000000001A';

const OTHER_DATED_REPORT = '01REPORT000000000000002B';

const UNDATED_REPORT = '01REPORT000000000000003C';

const GROUPED_REPORT = '01REPORT000000000000004D';

const USER_ID = '01USER00000000000000001A';

const TEAM_ID = '01TEAM00000000000000001A';

const GROUPED_REFUSAL_CODE = 'grouped_report';

const REPORT_OPTIONS: SelectOption[] = [
    { value: DATED_REPORT, label: 'Umsatz gesamt' },
    { value: OTHER_DATED_REPORT, label: 'Abschlüsse gesamt' },
    { value: UNDATED_REPORT, label: 'Offene Aufgaben' },
    {
        value: GROUPED_REPORT,
        label: 'Umsatz nach Phase',
        disabled: true,
        disabledReason: GROUPED_REFUSAL_CODE,
    },
];

const SCOPE_FIELDS_BY_REPORT: Record<string, SelectOption[]> = {
    [DATED_REPORT]: [
        { value: 'owner_id', label: 'Besitzer' },
        { value: 'team_id', label: 'Team' },
        { value: 'pipeline', label: 'Pipeline' },
    ],
    [OTHER_DATED_REPORT]: [
        { value: 'owner_id', label: 'Besitzer' },
        { value: 'team_id', label: 'Team' },
    ],
    [UNDATED_REPORT]: [
        { value: 'owner_id', label: 'Besitzer' },
        { value: 'team_id', label: 'Team' },
    ],
};

const PERIOD_FIELDS_BY_REPORT: Record<string, SelectOption[]> = {
    [DATED_REPORT]: [{ value: 'closed_on', label: 'Abschlussdatum' }],
    [OTHER_DATED_REPORT]: [{ value: 'due_on', label: 'Fälligkeit' }],
    [UNDATED_REPORT]: [],
};

const USER_OPTIONS: SelectOption[] = [
    {
        value: USER_ID,
        label: 'Anna Albers',
        description: 'anna@nubos.de',
        avatar: { name: 'Anna Albers' },
    },
];

const TEAM_OPTIONS: SelectOption[] = [{ value: TEAM_ID, label: 'Vertrieb' }];

const SCOPE_TYPE_OPTIONS: SelectOption[] = [
    { value: 'user', label: 'Nutzer' },
    { value: 'team', label: 'Team' },
    { value: 'tenant', label: 'Mandant' },
];

const PERIOD_TYPE_OPTIONS: SelectOption[] = [
    { value: 'month', label: 'Monat' },
    { value: 'quarter', label: 'Quartal' },
    { value: 'year', label: 'Jahr' },
];

const DIRECTION_OPTIONS: SelectOption[] = [
    { value: 'at_least', label: 'mindestens erreichen' },
    { value: 'at_most', label: 'höchstens überschreiten' },
];

const ALWAYS_VISIBLE_ERROR_KEYS = [
    'name',
    'report_id',
    'scope_type',
    'target_user_id',
    'scope_field_key',
    'period_field_key',
    'period_type',
    'direction',
    'target_value',
];

function period(overrides: Partial<GoalPeriod> = {}): GoalPeriod {
    return {
        id: '01PERIOD0000000000000A01',
        goal_id: GOAL_ID,
        period_start: '2026-08-05T00:00:00.000Z',
        period_end: '2026-09-05T00:00:00.000Z',
        current_value: '4200.0000',
        calculated_at: '2026-08-11T10:00:00.000Z',
        ...overrides,
    };
}

function goalRow(overrides: Partial<GoalListRow> = {}): GoalListRow {
    return {
        id: GOAL_ID,
        name: 'Umsatz im Monat',
        report_id: DATED_REPORT,
        report: {
            id: DATED_REPORT,
            name: 'Umsatz gesamt',
            object_type_id: '01OBJECTTYPE00000000001A',
        },
        scope_type: 'user',
        target_user_id: USER_ID,
        target_team_id: null,
        target: USER_OPTIONS[0],
        includes_subteams: false,
        scope_field_key: 'owner_id',
        period_field_key: null,
        period_type: 'month',
        direction: 'at_least',
        target_value: '10000.0000',
        periods: [period()],
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountForm(props: Record<string, unknown> = {}): VueWrapper {
    return mount(Form, {
        props: {
            mode: 'create',
            goal: null,
            reportOptions: REPORT_OPTIONS,
            scopeFieldsByReport: SCOPE_FIELDS_BY_REPORT,
            periodFieldsByReport: PERIOD_FIELDS_BY_REPORT,
            userOptions: USER_OPTIONS,
            teamOptions: TEAM_OPTIONS,
            scopeTypeOptions: SCOPE_TYPE_OPTIONS,
            periodTypeOptions: PERIOD_TYPE_OPTIONS,
            directionOptions: DIRECTION_OPTIONS,
            ...props,
        },
        global: {
            stubs: { ...selectStubs, ...comboboxStubs },
        },
    });
}

function mountEdit(overrides: Partial<GoalListRow> = {}): VueWrapper {
    return mountForm({ mode: 'edit', goal: goalRow(overrides) });
}

function slot(
    wrapper: VueWrapper,
    name: string,
): Omit<DOMWrapper<Element>, 'exists'> {
    return wrapper.get(`[data-goal-field="${name}"]`);
}

function control(wrapper: VueWrapper, name: string): DOMWrapper<Element> {
    const found = slot(wrapper, name).find('select');

    if (!found.exists()) {
        throw new Error(`Field "${name}" renders no control`);
    }

    return found;
}

function optionValues(wrapper: VueWrapper, name: string): string[] {
    return slot(wrapper, name)
        .findAll('option')
        .map((option) => option.attributes('value') ?? '');
}

function isSaveDisabled(wrapper: VueWrapper): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') !== undefined;
}

function formState(): FormState {
    if (inertia.form === null) {
        throw new Error('The Inertia form was never created');
    }

    return inertia.form;
}

function submittedPayload(): Record<string, unknown> {
    const apply = inertia.transform.apply;

    if (apply === null) {
        throw new Error(
            'The form never transformed its payload before sending',
        );
    }

    return apply({});
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(NOW));
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.visit.mockReset();
    inertia.transform.apply = null;
    inertia.page.props.auth.authority = null;
    formState().errors = {};
    formState().processing = false;
});

afterEach(() => {
    vi.useRealTimers();
});

describe('goals/Form — unsaved changes', () => {
    it('keeps saving disabled until something actually changed', () => {
        expect(isSaveDisabled(mountForm())).toBe(true);
        expect(isSaveDisabled(mountEdit())).toBe(true);
    });

    it('counts a change of the period type as a change', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'period-type').setValue('quarter');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the direction as a change', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'direction').setValue('at_most');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the scope type as a change', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'scope-type').setValue('team');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the source report as a change', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'report').setValue(OTHER_DATED_REPORT);

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the subteam switch as a change', async () => {
        const wrapper = mountEdit({
            scope_type: 'team',
            target_user_id: null,
            target_team_id: TEAM_ID,
            target: TEAM_OPTIONS[0],
            scope_field_key: 'team_id',
        });

        wrapper.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'period-type').setValue('quarter');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
        expect(inertia.visit).not.toHaveBeenCalled();
    });
});

describe('goals/Form — action row', () => {
    it('submits through the shared save button, also while creating', () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
        expect(wrapper.find('[data-form-actions]').exists()).toBe(true);
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
    });

    it('posts to the store route while creating', async () => {
        const wrapper = mountForm();

        await wrapper.get('#goal-name').setValue('Umsatz im Quartal');
        await control(wrapper, 'report').setValue(DATED_REPORT);
        await wrapper.get('form').trigger('submit');

        expect(inertia.post.mock.calls[0][0]).toBe(GoalsController.store.url());
        expect(submittedPayload().name).toBe('Umsatz im Quartal');
        expect(submittedPayload().report_id).toBe(DATED_REPORT);
    });

    it('puts to the update route while editing', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#goal-name').setValue('Umsatz im Monat 2027');
        await wrapper.get('form').trigger('submit');

        expect(inertia.put.mock.calls[0][0]).toBe(
            GoalsController.update.url({ goal: GOAL_ID }),
        );
        expect(submittedPayload().name).toBe('Umsatz im Monat 2027');
    });
});

describe('goals/Form — the source report', () => {
    it('offers a grouped report disabled instead of hiding it', () => {
        const wrapper = mountForm();
        const options = slot(wrapper, 'report').findAll('option');
        const values = options.map((option) => option.attributes('value'));
        const grouped = options[values.indexOf(GROUPED_REPORT)];

        expect(values).toContain(GROUPED_REPORT);
        expect(grouped.attributes('disabled')).toBeDefined();
        expect(grouped.text()).toContain('Umsatz nach Phase');
        expect(wrapper.findComponent({ name: 'Tooltip' }).exists()).toBe(false);
    });

    it('translates the refusal code of a grouped report into German prose', () => {
        const reason = resolveGoalOptionRefusal(GROUPED_REFUSAL_CODE);

        expect(reason).toBeDefined();
        expect(String(reason).length).toBeGreaterThan(10);
        expect(reason).not.toBe(GROUPED_REFUSAL_CODE);
        expect(resolveGoalOptionRefusal(null)).toBeUndefined();
    });
});

describe('goals/Form — the assignment', () => {
    it('picks the target from a list and never offers a free text field for an id', async () => {
        const wrapper = mountEdit();

        expect(
            slot(wrapper, 'target').find('select.ui-combobox').exists(),
        ).toBe(true);
        expect(slot(wrapper, 'target').findAll('input')).toHaveLength(0);
        expect(slot(wrapper, 'target').text()).toContain('Anna Albers');

        await control(wrapper, 'scope-type').setValue('team');

        expect(
            slot(wrapper, 'target').find('select.ui-combobox').exists(),
        ).toBe(true);
        expect(slot(wrapper, 'target').findAll('input')).toHaveLength(0);
        expect(slot(wrapper, 'target').text()).toContain('Vertrieb');
    });

    it('shows the subteam switch for a team goal only', async () => {
        const wrapper = mountEdit();

        expect(wrapper.find('[data-goal-field="subteams"]').exists()).toBe(
            false,
        );

        await control(wrapper, 'scope-type').setValue('team');

        expect(wrapper.find('[data-goal-field="subteams"]').exists()).toBe(
            true,
        );
        expect(wrapper.findComponent(Checkbox).exists()).toBe(true);

        await control(wrapper, 'scope-type').setValue('tenant');

        expect(wrapper.find('[data-goal-field="subteams"]').exists()).toBe(
            false,
        );
    });

    it('drops target and restriction field for a tenant goal, in the form and in the payload', async () => {
        const wrapper = mountEdit();

        expect(wrapper.find('[data-goal-field="target"]').exists()).toBe(true);
        expect(wrapper.find('[data-goal-field="scope-field"]').exists()).toBe(
            true,
        );

        await control(wrapper, 'scope-type').setValue('tenant');

        expect(wrapper.find('[data-goal-field="target"]').exists()).toBe(false);
        expect(wrapper.find('[data-goal-field="scope-field"]').exists()).toBe(
            false,
        );

        await wrapper.get('form').trigger('submit');

        const payload = submittedPayload();

        expect(payload.scope_type).toBe('tenant');
        expect(payload.target_user_id).toBeNull();
        expect(payload.target_team_id).toBeNull();
        expect(payload.scope_field_key).toBeNull();
    });

    it('prefills the restriction field from the chosen scope', async () => {
        const wrapper = mountForm();

        await control(wrapper, 'report').setValue(DATED_REPORT);
        await control(wrapper, 'scope-type').setValue('team');
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().scope_field_key).toBe('team_id');
    });

    it('prefills the restriction field of the initial scope once a report is chosen', async () => {
        const wrapper = mountForm();

        await control(wrapper, 'report').setValue(DATED_REPORT);

        expect(
            slot(wrapper, 'scope-field').get<HTMLSelectElement>('select')
                .element.value,
        ).toBe('owner_id');

        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().scope_type).toBe('user');
        expect(submittedPayload().scope_field_key).toBe('owner_id');
    });
});

describe('goals/Form — the time reference', () => {
    it('offers the snapshot option next to the date fields of the chosen report', async () => {
        const wrapper = mountForm();

        await control(wrapper, 'report').setValue(DATED_REPORT);

        expect(optionValues(wrapper, 'period-field')).toEqual([
            '',
            'closed_on',
        ]);
        expect(slot(wrapper, 'period-field').text()).toContain(
            'Momentaufnahme',
        );

        await control(wrapper, 'report').setValue(OTHER_DATED_REPORT);

        expect(optionValues(wrapper, 'period-field')).toEqual(['', 'due_on']);
    });

    it('offers the snapshot option alone for a report without a date field', async () => {
        const wrapper = mountForm();

        await control(wrapper, 'report').setValue(UNDATED_REPORT);

        expect(optionValues(wrapper, 'period-field')).toEqual(['']);
        expect(
            control(wrapper, 'period-field').attributes('disabled'),
        ).toBeUndefined();
    });

    it('drops a period field the newly chosen report does not offer', async () => {
        const wrapper = mountEdit({ period_field_key: 'closed_on' });

        await control(wrapper, 'report').setValue(OTHER_DATED_REPORT);
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().report_id).toBe(OTHER_DATED_REPORT);
        expect(submittedPayload().period_field_key).toBeNull();
    });

    it('keeps a restriction field the newly chosen report still offers', async () => {
        const wrapper = mountEdit({ scope_field_key: 'owner_id' });

        await control(wrapper, 'report').setValue(OTHER_DATED_REPORT);
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().scope_field_key).toBe('owner_id');
    });

    it('drops a restriction field the newly chosen report no longer offers', async () => {
        const wrapper = mountEdit({ scope_field_key: 'pipeline' });

        await control(wrapper, 'report').setValue(OTHER_DATED_REPORT);
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().scope_field_key).toBeNull();
    });
});

describe('goals/Form — controls and progress', () => {
    it('renders no native select anywhere', () => {
        const wrapper = mountForm();

        const native = wrapper
            .findAll('select')
            .filter(
                (element) =>
                    !element.classes('ui-select') &&
                    !element.classes('ui-combobox'),
            );

        expect(native).toHaveLength(0);
    });

    it('shows progress and history while editing and neither while creating', () => {
        const edit = mountEdit();

        expect(edit.findComponent(GoalProgressCard).exists()).toBe(true);
        expect(edit.findComponent(GoalPeriodHistory).exists()).toBe(true);

        const create = mountForm();

        expect(create.findComponent(GoalProgressCard).exists()).toBe(false);
        expect(create.findComponent(GoalPeriodHistory).exists()).toBe(false);
    });

    it('starts every field of a card at its top edge so a hint cannot push the neighbour down', () => {
        const wrapper = mountForm();

        const shared = new Set(
            wrapper
                .findAll('[data-goal-field]')
                .map((field) => field.element.parentElement)
                .filter(
                    (parent): parent is HTMLElement =>
                        parent !== null &&
                        parent.querySelectorAll('[data-goal-field]').length > 1,
                ),
        );

        expect(shared.size).toBeGreaterThan(0);

        shared.forEach((card) => {
            expect(card.className).toContain('items-start');
        });
    });

    it('explains what the two directions mean in German', () => {
        const wrapper = mountForm();

        const hint = slot(wrapper, 'direction').text();

        expect(hint.length).toBeGreaterThan(20);
        expect(hint).not.toContain('at_least');
        expect(hint).not.toContain('at_most');
    });
});

describe('goals/Form — server side errors', () => {
    it('renders a bound message for every key a user goal can be rejected on', async () => {
        const wrapper = mountEdit();

        const errors: Record<string, string> = {};

        ALWAYS_VISIBLE_ERROR_KEYS.forEach((key) => {
            errors[key] = `FEHLER_${key.toUpperCase()}`;
        });

        formState().errors = errors;
        await nextTick();

        expect(ALWAYS_VISIBLE_ERROR_KEYS).toHaveLength(9);

        ALWAYS_VISIBLE_ERROR_KEYS.forEach((key) => {
            expect(wrapper.text()).toContain(`FEHLER_${key.toUpperCase()}`);
        });
    });

    it('renders the messages a team goal can be rejected on', async () => {
        const wrapper = mountEdit();

        await control(wrapper, 'scope-type').setValue('team');

        formState().errors = {
            target_team_id: 'FEHLER_TARGET_TEAM_ID',
            includes_subteams: 'FEHLER_INCLUDES_SUBTEAMS',
        };
        await nextTick();

        expect(wrapper.text()).toContain('FEHLER_TARGET_TEAM_ID');
        expect(wrapper.text()).toContain('FEHLER_INCLUDES_SUBTEAMS');
    });
});
