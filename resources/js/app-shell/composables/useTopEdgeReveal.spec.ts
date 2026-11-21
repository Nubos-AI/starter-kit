import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, useTemplateRef } from 'vue';
import { useTopEdgeReveal } from './useTopEdgeReveal';

const Header = defineComponent({
  setup() {
    const header = useTemplateRef<HTMLElement>('header');
    const { isHidden } = useTopEdgeReveal(header);

    return () =>
      h('header', { ref: 'header', 'data-hidden': String(isHidden.value) }, [h('button', { 'data-menu': '' }, 'Menü')]);
  },
});

let wrapper: VueWrapper | null = null;

function scrollTo(position: number): void {
  Object.defineProperty(window, 'scrollY', {
    value: position,
    configurable: true,
  });
  window.dispatchEvent(new Event('scroll'));
}

function movePointer(clientY: number, target: EventTarget = document): void {
  target.dispatchEvent(new PointerEvent('pointermove', { clientY, bubbles: true }));
}

async function mountHeader(): Promise<VueWrapper> {
  wrapper = mount(Header, { attachTo: document.body });
  await nextTick();

  return wrapper;
}

function isHidden(header: VueWrapper): boolean {
  return header.get('header').attributes('data-hidden') === 'true';
}

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  scrollTo(0);
});

describe('useTopEdgeReveal', () => {
  it('keeps the header visible at the top of the page', async () => {
    const header = await mountHeader();

    expect(isHidden(header)).toBe(false);
  });

  it('hides the header once the page is scrolled past it', async () => {
    const header = await mountHeader();

    scrollTo(400);
    await nextTick();

    expect(isHidden(header)).toBe(true);
  });

  it('reveals the header when the pointer reaches the top edge', async () => {
    const header = await mountHeader();
    scrollTo(400);

    movePointer(8);
    await nextTick();

    expect(isHidden(header)).toBe(false);
  });

  it('keeps the revealed header while the pointer rests on it', async () => {
    const header = await mountHeader();
    scrollTo(400);
    movePointer(8);

    movePointer(30, header.get('[data-menu]').element);
    await nextTick();

    expect(isHidden(header)).toBe(false);
  });

  it('hides the revealed header again when the pointer leaves it', async () => {
    const header = await mountHeader();
    scrollTo(400);
    movePointer(8);

    movePointer(300);
    await nextTick();

    expect(isHidden(header)).toBe(true);
  });

  it('hides the revealed header when the page scrolls on', async () => {
    const header = await mountHeader();
    scrollTo(400);
    movePointer(8);

    scrollTo(500);
    await nextTick();

    expect(isHidden(header)).toBe(true);
  });

  it('hides the revealed header after a click inside it once the pointer leaves', async () => {
    const header = await mountHeader();
    scrollTo(400);
    movePointer(8);
    const menu = header.get('[data-menu]').element;

    if (menu instanceof HTMLElement) {
      menu.focus();
    }

    movePointer(300);
    await nextTick();

    expect(isHidden(header)).toBe(true);
  });

  it('keeps the header while one of its menus is open', async () => {
    const header = await mountHeader();
    scrollTo(400);
    movePointer(8);
    header.get('[data-menu]').element.setAttribute('data-state', 'open');

    movePointer(300);
    await nextTick();

    expect(isHidden(header)).toBe(false);
  });
});
