import type { DOMWrapper, VueWrapper } from '@vue/test-utils';

export interface SectionLayout {
  isCard: boolean;
  nestedCards: number;
  saves: number;
  endsWithSave: boolean;
}

function isVisible(element: Element): boolean {
  return !(element instanceof HTMLElement) || element.style.display !== 'none';
}

export function sectionKeys(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-section-key]').map((tab) => tab.attributes('data-section-key') ?? '');
}

export function erroredSections(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-section-errors]').map((tab) => tab.attributes('data-section-key') ?? '');
}

export function activeSection(wrapper: VueWrapper): string {
  return wrapper.get('[data-section-active]').attributes('data-section-key') ?? '';
}

export async function openSection(wrapper: VueWrapper, key: string): Promise<void> {
  await wrapper.get(`[data-section-key="${key}"]`).trigger('click');
}

export function sectionPanel(wrapper: VueWrapper, key: string): Omit<DOMWrapper<Element>, 'exists'> {
  return wrapper.get(`[data-section-panel="${key}"]`);
}

export function shownSections(wrapper: VueWrapper): string[] {
  return wrapper
    .findAll('[data-section-panel]')
    .filter((panel) => isVisible(panel.element))
    .map((panel) => panel.attributes('data-section-panel') ?? '');
}

export function panelSaves(wrapper: VueWrapper): Record<string, number> {
  return Object.fromEntries(
    wrapper
      .findAll('[data-section-panel]')
      .map((panel) => [panel.attributes('data-section-panel') ?? '', panel.findAll('[data-form-save]').length]),
  );
}

export function activeSave(wrapper: VueWrapper): Omit<DOMWrapper<Element>, 'exists'> {
  return sectionPanel(wrapper, activeSection(wrapper)).get('[data-form-save]');
}

export function sectionLayouts(wrapper: VueWrapper): Record<string, SectionLayout> {
  return Object.fromEntries(
    sectionKeys(wrapper).map((section) => {
      const panel = sectionPanel(wrapper, section);
      const content = panel.find('[data-slot="card-content"]');
      const lastChild = content.exists() ? content.element.lastElementChild : null;

      return [
        section,
        {
          isCard: panel.attributes('data-slot') === 'card',
          nestedCards: panel.findAll('[data-slot="card"]').length,
          saves: panel.findAll('[data-form-save]').length,
          endsWithSave: lastChild?.hasAttribute('data-form-actions') ?? false,
        },
      ];
    }),
  );
}
