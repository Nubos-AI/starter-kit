import { computed } from 'vue';
import { useUiExtensions } from '@/shared/composables/useUiExtensions';
import type { SelectOption } from '@/shared/types/ui';

export function useModuleOptions(point: string, defaults: SelectOption[] = []) {
  const state = useUiExtensions();

  return computed<SelectOption[]>(() => [...defaults, ...(state.value.options[point] ?? [])]);
}
