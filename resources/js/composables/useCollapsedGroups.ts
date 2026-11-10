import type { Ref } from 'vue';
import { ref } from 'vue';

export interface UseCollapsedGroupsReturn {
    collapsed: Ref<Set<string>>;
    toggle: (groupId: string) => void;
}

export function useCollapsedGroups(): UseCollapsedGroupsReturn {
    const collapsed = ref<Set<string>>(new Set());

    function toggle(groupId: string): void {
        const next = new Set(collapsed.value);

        if (!next.delete(groupId)) {
            next.add(groupId);
        }

        collapsed.value = next;
    }

    return { collapsed, toggle };
}
