import { useSectionList } from '@/composables/useSectionList';

export interface UseCollapsedSectionsReturn {
    isOpen: (sectionId: string) => boolean;
    setOpen: (sectionId: string, open: boolean) => void;
}

export function useCollapsedSections(
    objectTypeId: string | null,
): UseCollapsedSectionsReturn {
    const collapsed = useSectionList(objectTypeId, 'collapsedSections');

    return {
        isOpen: (sectionId) => !collapsed.contains(sectionId),
        setOpen: (sectionId, open) => collapsed.set(sectionId, !open),
    };
}
