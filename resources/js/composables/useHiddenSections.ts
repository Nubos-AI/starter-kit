import type { ComputedRef } from 'vue';
import { useSectionList } from '@/composables/useSectionList';

export interface UseHiddenSectionsReturn {
    hidden: ComputedRef<string[]>;
    isVisible: (sectionId: string) => boolean;
    setVisible: (sectionId: string, visible: boolean) => void;
}

export function useHiddenSections(
    objectTypeId: string | null,
): UseHiddenSectionsReturn {
    const hidden = useSectionList(objectTypeId, 'hiddenSections');

    return {
        hidden: hidden.entries,
        isVisible: (sectionId) => !hidden.contains(sectionId),
        setVisible: (sectionId, visible) => hidden.set(sectionId, !visible),
    };
}
