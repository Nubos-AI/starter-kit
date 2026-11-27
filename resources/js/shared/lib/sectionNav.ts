import type { ComputedRef, InjectionKey, Ref, Slot } from 'vue';

export interface SectionNavContext {
  active: Ref<string | null>;
  isNavigable: ComputedRef<boolean>;
  actions: () => Slot | undefined;
  hasActions: (section: string) => boolean;
  register: (section: string, offersActions: () => boolean) => () => void;
}

export const sectionNavKey: InjectionKey<SectionNavContext> = Symbol('sectionNav');
