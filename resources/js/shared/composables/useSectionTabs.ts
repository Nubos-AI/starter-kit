import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import type { SectionNavEntry } from '@/shared/types/navigation';

export interface SectionTabDefinition {
  key: string;
  title: string;
  fields?: string[];
}

export interface SectionTabsState {
  active: Ref<string>;
  sections: ComputedRef<SectionNavEntry[]>;
  revealErrors: (received?: Partial<Record<string, string>>) => void;
}

const minimumNavigableSections = 3;

function matchLength(errorKey: string, field: string): number {
  const keyParts = errorKey.split('.');
  const fieldParts = field.split('.');

  if (fieldParts.length > keyParts.length) {
    return 0;
  }

  return fieldParts.every((part, index) => part === '*' || part === keyParts[index]) ? fieldParts.length : 0;
}

function precisionOf(errorKey: string, fields: string[]): number {
  return Math.max(0, ...fields.map((field) => matchLength(errorKey, field)));
}

function ownersOf(definitions: SectionTabDefinition[], errors: Partial<Record<string, string>>): (string | null)[] {
  return Object.keys(errors)
    .filter((key) => (errors[key] ?? '') !== '')
    .map(
      (key) =>
        definitions.reduce<{ key: string | null; precision: number }>(
          (best, definition) => {
            const precision = precisionOf(key, definition.fields ?? []);

            return precision > best.precision ? { key: definition.key, precision } : best;
          },
          { key: null, precision: 0 },
        ).key,
    );
}

export function useSectionDefinition(titlePrefix: string): (key: string, fields?: string[]) => SectionTabDefinition {
  const { t } = useI18n();

  return (key, fields = []) => ({
    key,
    title: t(`${titlePrefix}.${key}`),
    fields,
  });
}

export function useSectionTabs(
  definitions: () => SectionTabDefinition[],
  errors: () => Partial<Record<string, string>>,
  initial?: string,
): SectionTabsState {
  const resolved = computed<SectionTabDefinition[]>(() => definitions());

  const active = ref<string>(initial ?? resolved.value[0]?.key ?? '');

  const isNavigable = computed<boolean>(() => resolved.value.length >= minimumNavigableSections);

  const sections = computed<SectionNavEntry[]>(() => {
    if (!isNavigable.value) {
      return [];
    }

    const owners = ownersOf(resolved.value, errors());

    return resolved.value.map((definition) => ({
      key: definition.key,
      title: definition.title,
      hasErrors: owners.includes(definition.key),
    }));
  });

  function revealErrors(received?: Partial<Record<string, string>>): void {
    const owners = ownersOf(resolved.value, received ?? errors());

    if (owners.includes(active.value)) {
      return;
    }

    const errored = resolved.value.find((definition) => owners.includes(definition.key));

    if (errored !== undefined) {
      active.value = errored.key;
    }
  }

  return { active, sections, revealErrors };
}
