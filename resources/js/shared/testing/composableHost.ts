import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';

const hosts: VueWrapper[] = [];

export function inComponent<T>(composable: () => T): T {
  const holder: { result?: { value: T } } = {};

  hosts.push(
    mount(
      defineComponent({
        setup() {
          holder.result = { value: composable() };

          return () => null;
        },
      }),
    ),
  );

  if (holder.result === undefined) {
    throw new Error('The composable was not set up.');
  }

  return holder.result.value;
}

export function unmountComposableHosts(): void {
  hosts.splice(0).forEach((host) => host.unmount());
}
