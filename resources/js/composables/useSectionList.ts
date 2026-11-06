import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { useUserPreferences } from '@/composables/useUserPreferences';

export type SectionListKey = 'collapsedSections' | 'hiddenSections';

export interface UseSectionListReturn {
    entries: ComputedRef<string[]>;
    contains: (sectionId: string) => boolean;
    set: (sectionId: string, present: boolean) => void;
}

export function useSectionList(
    objectTypeId: string | null,
    key: SectionListKey,
): UseSectionListReturn {
    const preferences = useUserPreferences();
    const local: Ref<string[]> = ref([]);

    const entries = computed<string[]>(() =>
        objectTypeId === null
            ? local.value
            : (preferences.objectType(objectTypeId)[key] ?? []),
    );

    const contains = (sectionId: string): boolean =>
        entries.value.includes(sectionId);

    const set = (sectionId: string, present: boolean): void => {
        if (present === contains(sectionId)) {
            return;
        }

        const next = present
            ? [...entries.value, sectionId]
            : entries.value.filter((entry) => entry !== sectionId);

        if (objectTypeId === null) {
            local.value = next;

            return;
        }

        preferences.patch({ objectTypes: { [objectTypeId]: { [key]: next } } });
    };

    return { entries, contains, set };
}
