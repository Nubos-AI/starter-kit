const FIELD_KEY_PREFIX = 'data.';

const CONDITION_KEY_ROOT = 'gate.condition';

const UNEVALUABLE_KEY = 'gate.unevaluable';

const CONDITION_MESSAGE = 'Eine hinterlegte Bedingung ist nicht erfüllt.';

const UNEVALUABLE_MESSAGE = 'Das Gate konnte nicht geprüft werden.';

const UNEXPLAINED_MESSAGE = 'Die Änderung wurde ohne Begründung abgelehnt.';

const FIELD_DEMAND_PATTERN = /^Das Feld "([^"]*)" (.+)$/;

const REQUIRED_DEMAND = 'muss vor dem Stagewechsel ausgefüllt sein.';

const REQUIRED_MESSAGE = 'muss ausgefüllt sein.';

const SYSTEM_FIELD_LABELS: Record<string, string> = {
    owner_id: 'Besitzer',
    team_id: 'Team',
    stage_id: 'Stage',
};

export interface GateViolationLine {
    key: string;
    message: string;
}

export function gateViolationLines(
    errors: Record<string, string[]>,
    fieldLabels: Record<string, string> = {},
): GateViolationLine[] {
    return Object.entries(errors).map(([key, messages]) =>
        translate(key, messages[0] ?? null, fieldLabels),
    );
}

function fieldLabel(
    key: string,
    messageFieldKey: string,
    fieldLabels: Record<string, string>,
): string {
    const dataKey = key.startsWith(FIELD_KEY_PREFIX)
        ? key.slice(FIELD_KEY_PREFIX.length)
        : key;

    return (
        fieldLabels[messageFieldKey] ??
        fieldLabels[dataKey] ??
        SYSTEM_FIELD_LABELS[dataKey] ??
        messageFieldKey
    );
}

function translate(
    key: string,
    message: string | null,
    fieldLabels: Record<string, string>,
): GateViolationLine {
    if (key === UNEVALUABLE_KEY) {
        return { key, message: UNEVALUABLE_MESSAGE };
    }

    if (message === null) {
        return { key, message: UNEXPLAINED_MESSAGE };
    }

    const demand = FIELD_DEMAND_PATTERN.exec(message);

    if (demand !== null) {
        const label = fieldLabel(key, demand[1], fieldLabels);
        const wording =
            demand[2] === REQUIRED_DEMAND ? REQUIRED_MESSAGE : demand[2];

        return { key, message: `${label} ${wording}` };
    }

    if (
        key === CONDITION_KEY_ROOT ||
        key.startsWith(`${CONDITION_KEY_ROOT}.`)
    ) {
        return { key, message: CONDITION_MESSAGE };
    }

    return { key, message };
}
