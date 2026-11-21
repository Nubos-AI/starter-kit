import type { DOMWrapper } from '@vue/test-utils';

interface ButtonHost {
  findAll: (selector: 'button') => DOMWrapper<HTMLButtonElement>[];
}

export function buttonContaining(wrapper: ButtonHost, text: string): DOMWrapper<HTMLButtonElement> | undefined {
  return wrapper.findAll('button').find((button) => button.text().includes(text));
}

export function buttonLabelled(wrapper: ButtonHost, label: string): DOMWrapper<HTMLButtonElement> | undefined {
  return wrapper.findAll('button').find((button) => button.text().trim() === label);
}
