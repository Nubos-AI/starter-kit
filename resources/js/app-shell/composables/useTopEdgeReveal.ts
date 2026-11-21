import { defaultDocument, defaultWindow, useEventListener } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';

export interface TopEdgeReveal {
  isHidden: ComputedRef<boolean>;
}

export function useTopEdgeReveal(target: Readonly<Ref<HTMLElement | null>>): TopEdgeReveal {
  const edge = 10;
  const isScrolledPast = ref<boolean>(false);
  const isRevealed = ref<boolean>(false);

  function contains(eventTarget: EventTarget | null): boolean {
    return eventTarget instanceof Node && (target.value?.contains(eventTarget) ?? false);
  }

  function hasOpenMenu(): boolean {
    return Boolean(target.value?.querySelector('[data-state="open"]'));
  }

  function onScroll(): void {
    isScrolledPast.value = window.scrollY > (target.value?.offsetHeight ?? 0);

    if (isRevealed.value && !hasOpenMenu()) {
      isRevealed.value = false;
    }
  }

  function onPointerMove(event: PointerEvent): void {
    if (event.clientY <= edge) {
      isRevealed.value = true;

      return;
    }

    if (isRevealed.value && !contains(event.target) && !hasOpenMenu()) {
      isRevealed.value = false;
    }
  }

  function onFocusIn(event: FocusEvent): void {
    if (event.target instanceof HTMLElement && event.target.matches(':focus-visible') && contains(event.target)) {
      isRevealed.value = true;
    }
  }

  onMounted(onScroll);
  useEventListener(defaultWindow, 'scroll', onScroll, { passive: true });
  useEventListener(defaultDocument, 'pointermove', onPointerMove, {
    passive: true,
  });
  useEventListener(defaultDocument, 'focusin', onFocusIn);

  const isHidden = computed<boolean>(() => isScrolledPast.value && !isRevealed.value);

  return { isHidden };
}
