import { injectTabsRootContext } from 'reka-ui';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { ComputedRef, Ref, ShallowRef } from 'vue';

export interface OverflowTab {
  value: string;
  label: string;
  disabled: boolean;
}

export interface TabsOverflow {
  activeValue: ComputedRef<string>;
  hiddenTabs: Ref<OverflowTab[]>;
  isHiddenTabActive: ComputedRef<boolean>;
  selectTab: (value: string) => void;
  measure: () => void;
}

export function useTabsOverflow(
  container: Readonly<ShallowRef<HTMLElement | null>>,
  isEnabled: () => boolean,
): TabsOverflow {
  const rootContext = injectTabsRootContext();
  const triggerIdPrefix = `${rootContext.baseId}-trigger-`;
  const roundingTolerance = 0.5;
  const hiddenTabs = ref<OverflowTab[]>([]);
  let resizeObserver: ResizeObserver | null = null;
  let mutationObserver: MutationObserver | null = null;

  const activeValue = computed<string>(() => String(rootContext.modelValue.value ?? ''));
  const isHiddenTabActive = computed<boolean>(() => hiddenTabs.value.some((tab) => tab.value === activeValue.value));

  function widthOf(element: Element): number {
    return element.getBoundingClientRect().width;
  }

  function pixels(value: string): number {
    return Number.parseFloat(value) || 0;
  }

  function triggersOf(list: HTMLElement): HTMLElement[] {
    const triggers = list.querySelectorAll<HTMLElement>('[role="tab"]');

    return Array.from(triggers).filter((trigger) => trigger.id.startsWith(triggerIdPrefix));
  }

  function naturalWidthOf(trigger: HTMLElement): number {
    const previousFlex = trigger.style.flex;

    trigger.hidden = false;
    trigger.style.flex = 'none';

    const width = widthOf(trigger);

    trigger.style.flex = previousFlex;

    return width;
  }

  function toOverflowTab(trigger: HTMLElement): OverflowTab {
    return {
      value: trigger.id.slice(triggerIdPrefix.length),
      label: trigger.textContent?.trim() ?? '',
      disabled: trigger.dataset.disabled === '',
    };
  }

  function measure(): void {
    const wrapper = container.value;
    const list = wrapper?.querySelector<HTMLElement>('[role="tablist"]');

    if (!wrapper || !list) {
      return;
    }

    const triggers = triggersOf(list);
    const naturalWidths = triggers.map(naturalWidthOf);
    const wrapperStyle = getComputedStyle(wrapper);
    const available = widthOf(wrapper) - pixels(wrapperStyle.paddingLeft) - pixels(wrapperStyle.paddingRight);
    const required = naturalWidths.reduce((sum, width) => sum + width, 0);
    const moreTrigger = wrapper.querySelector('[data-tabs-overflow-trigger]');
    const budget =
      required <= available + roundingTolerance
        ? available
        : available - pixels(wrapperStyle.columnGap) - (moreTrigger ? widthOf(moreTrigger) : 0);

    let used = 0;
    let isOverflowing = false;
    const hidden: OverflowTab[] = [];

    triggers.forEach((trigger, index) => {
      used += naturalWidths[index];
      isOverflowing = isOverflowing || used > budget + roundingTolerance;
      trigger.hidden = isOverflowing;

      if (isOverflowing) {
        hidden.push(toOverflowTab(trigger));
      }
    });

    hiddenTabs.value = hidden;
  }

  function selectTab(value: string): void {
    rootContext.changeModelValue(value);
  }

  onMounted(() => {
    const wrapper = container.value;

    if (!isEnabled() || !wrapper) {
      return;
    }

    measure();

    resizeObserver = new ResizeObserver(() => measure());
    resizeObserver.observe(wrapper);

    const list = wrapper.querySelector('[role="tablist"]');

    if (list) {
      mutationObserver = new MutationObserver(() => measure());
      mutationObserver.observe(list, {
        childList: true,
        subtree: true,
        characterData: true,
      });
    }
  });

  onUnmounted(() => {
    resizeObserver?.disconnect();
    mutationObserver?.disconnect();
    resizeObserver = null;
    mutationObserver = null;
  });

  return { activeValue, hiddenTabs, isHiddenTabActive, selectTab, measure };
}
