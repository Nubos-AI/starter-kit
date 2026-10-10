import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StageTransitionErrors from '@/components/engine/records/StageTransitionErrors.vue';

const SERVER_REQUIRED_AMOUNT =
    'Das Feld "amount" muss vor dem Stagewechsel ausgefüllt sein.';

const SERVER_REQUIRED_TITLE =
    'Das Feld "title" muss vor dem Stagewechsel ausgefüllt sein.';

const SERVER_CONDITION = 'The gate condition on "amount" is not satisfied.';

const SERVER_UNEVALUABLE = 'The gate could not be evaluated for this record.';

const violationFieldLabels: Record<string, string> = {
    amount: 'Betrag',
    title: 'Bezeichnung',
    owner_id: 'Besitzer',
};

type ViolationWrapper = ReturnType<typeof mount>;

function mountViolations(
    errors: Record<string, string[]>,
    ...handedIn: [Record<string, string> | undefined] | []
): ViolationWrapper {
    return mount(StageTransitionErrors, {
        props: {
            errors,
            fieldLabels:
                handedIn.length === 0 ? violationFieldLabels : handedIn[0],
        } as never,
    });
}

function violationRows(wrapper: ViolationWrapper): string[] {
    return wrapper
        .findAll('[data-transition-violation]')
        .map((row) => row.text());
}

describe('StageTransitionErrors — missing fields are named (SC-9)', () => {
    it('lists one named row per violated condition', () => {
        const wrapper = mountViolations({
            'data.amount': [SERVER_REQUIRED_AMOUNT],
            'data.title': [SERVER_REQUIRED_TITLE],
        });

        const rows = violationRows(wrapper);

        expect(rows).toHaveLength(2);
        expect(rows[0]).toBe('Betrag muss ausgefüllt sein.');
        expect(rows[1]).toBe('Bezeichnung muss ausgefüllt sein.');
    });

    it('falls back to the field key when no label was handed in', () => {
        const wrapper = mountViolations(
            { 'data.amount': [SERVER_REQUIRED_AMOUNT] },
            undefined,
        );

        expect(violationRows(wrapper)).toEqual([
            'amount muss ausgefüllt sein.',
        ]);
    });

    it('names an unprefixed field key through the handed in labels', () => {
        const wrapper = mountViolations({
            owner_id: [
                'Das Feld "owner" muss vor dem Stagewechsel ausgefüllt sein.',
            ],
        });

        expect(violationRows(wrapper)).toEqual([
            'Besitzer muss ausgefüllt sein.',
        ]);
    });

    it('names system columns in German without any handed in labels', () => {
        const wrapper = mountViolations(
            {
                owner_id: [
                    'Das Feld "owner" muss vor dem Stagewechsel ausgefüllt sein.',
                ],
                team_id: [
                    'Das Feld "team" muss vor dem Stagewechsel ausgefüllt sein.',
                ],
                stage_id: [
                    'Das Feld "stage" muss vor dem Stagewechsel ausgefüllt sein.',
                ],
            },
            {},
        );

        expect(violationRows(wrapper)).toEqual([
            'Besitzer muss ausgefüllt sein.',
            'Team muss ausgefüllt sein.',
            'Stage muss ausgefüllt sein.',
        ]);
    });

    it('names the field and what the condition demands', () => {
        const wrapper = mountViolations({
            'gate.condition.0': ['Das Feld "amount" muss größer als 100 sein.'],
        });

        expect(violationRows(wrapper)).toEqual([
            'Betrag muss größer als 100 sein.',
        ]);
    });

    it('keeps the technical condition path out of the message', () => {
        const wrapper = mountViolations({
            'gate.condition.0': ['Das Feld "amount" muss größer als 100 sein.'],
        });

        expect(wrapper.text()).not.toContain('gate.condition');
    });

    it('falls back to the condition text without a field key', () => {
        const wrapper = mountViolations({
            'gate.condition.0': [SERVER_CONDITION],
        });

        const rows = violationRows(wrapper);

        expect(rows).toHaveLength(1);
        expect(rows[0]).toContain(
            'Eine hinterlegte Bedingung ist nicht erfüllt.',
        );
    });

    it('reports an unevaluable gate in its own German sentence', () => {
        const wrapper = mountViolations({
            'gate.unevaluable': [SERVER_UNEVALUABLE],
        });

        const rows = violationRows(wrapper);

        expect(rows).toHaveLength(1);
        expect(rows[0]).toContain('Das Gate konnte nicht geprüft werden.');
    });

    it('never shows the raw English server message', () => {
        const wrapper = mountViolations({
            'data.amount': [SERVER_REQUIRED_AMOUNT],
            'gate.condition.0': [SERVER_CONDITION],
            'gate.unevaluable': [SERVER_UNEVALUABLE],
        });

        const text = wrapper.text();

        expect(text).not.toContain(SERVER_REQUIRED_AMOUNT);
        expect(text).not.toContain(SERVER_CONDITION);
        expect(text).not.toContain(SERVER_UNEVALUABLE);
        expect(text).not.toContain('must be filled');
        expect(text).not.toContain('is not satisfied');
        expect(text).not.toContain('The gate');
    });

    it('shows the server reason when the transition failed for another cause', () => {
        const wrapper = mountViolations({
            stage: [
                'No approver remains for this transition after exclusions and absences.',
            ],
        });

        expect(violationRows(wrapper)).toEqual([
            'No approver remains for this transition after exclusions and absences.',
        ]);
    });

    it('never claims a filled field is empty', () => {
        const wrapper = mountViolations({
            stage: ['This stage transition is not allowed.'],
        });

        expect(wrapper.text()).not.toContain('muss ausgefüllt sein');
    });

    it('renders nothing at all without violations', () => {
        const wrapper = mountViolations({});

        expect(wrapper.text()).toBe('');
        expect(wrapper.find('[data-transition-violation]').exists()).toBe(
            false,
        );
    });
});
