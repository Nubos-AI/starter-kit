import { computed } from 'vue';
import { useUiExtensions } from '@/composables/useUiExtensions';
import type { SelectOption } from '@/types/ui';

export function useModuleOptions(point: string, defaults: SelectOption[] = []) {
    const state = useUiExtensions();

    return computed<SelectOption[]>(() => [
        ...defaults,
        ...(state.value.options[point] ?? []),
    ]);
}
