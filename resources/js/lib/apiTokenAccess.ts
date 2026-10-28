import type { SelectOption } from '@/types/ui';

export const API_ACCESS_LEVELS = ['read', 'write'] as const;

export type ApiAccessLevel = (typeof API_ACCESS_LEVELS)[number];

export type ObjectTypeAccess = {
    objectType: string;
    levels: string[];
};

const ACCESS_LEVEL_LABELS: Record<ApiAccessLevel, string> = {
    read: 'Lesen',
    write: 'Schreiben',
};

const GLOBAL_SCOPE = 'records';

export const accessLevelOptions: SelectOption[] = API_ACCESS_LEVELS.map(
    (level) => ({ value: level, label: ACCESS_LEVEL_LABELS[level] }),
);

export function accessLevelLabel(level: string): string {
    return level in ACCESS_LEVEL_LABELS
        ? ACCESS_LEVEL_LABELS[level as ApiAccessLevel]
        : level;
}

export function formatAbility(
    ability: string,
    objectTypeLabels: Record<string, string> = {},
): string {
    const separator = ability.lastIndexOf(':');

    if (separator === -1) {
        return ability;
    }

    const scope = ability.slice(0, separator);
    const level = accessLevelLabel(ability.slice(separator + 1));
    const subject =
        scope === GLOBAL_SCOPE
            ? 'Alle Objekttypen'
            : (objectTypeLabels[scope] ?? scope);

    return `${subject}: ${level}`;
}
