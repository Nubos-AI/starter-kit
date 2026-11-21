import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import type { PropType } from 'vue';
import { activeSection, openSection, panelSaves, shownSections } from '@/shared/testing/sectionNav';
import type { SectionNavEntry } from '@/shared/types/navigation';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';

vi.mock('@inertiajs/vue3', async () => ({
  Link: (await import('@/shared/testing/inertia')).linkStub,
  usePage: () => ({ url: '/', props: {} }),
}));

const THREE_SECTIONS: SectionNavEntry[] = [
  { key: 'general', title: 'Allgemein' },
  { key: 'details', title: 'Details' },
  { key: 'history', title: 'Verlauf' },
];

function mountPage(entries: SectionNavEntry[], initial = 'general', withHistory = true) {
  const Page = defineComponent({
    components: { SectionNav, SectionPanel },
    props: {
      entries: {
        type: Array as PropType<SectionNavEntry[]>,
        required: true,
      },
    },
    setup() {
      return { active: ref<string>(initial), withHistory };
    },
    template: `
            <form>
                <SectionNav v-model="active" :entries="entries" label="Abschnitte">
                    <SectionPanel section="general">
                        <input id="name" required data-name-field />
                    </SectionPanel>
                    <SectionPanel section="details">
                        <input id="code" required data-code-field />
                    </SectionPanel>
                    <SectionPanel v-if="withHistory" section="history" :actions="false">
                        <p data-history>Verlauf</p>
                    </SectionPanel>
                    <template #actions>
                        <button type="submit" data-form-save>Speichern</button>
                    </template>
                </SectionNav>
            </form>
        `,
  });

  return mount(Page, { props: { entries }, attachTo: document.body });
}

describe('SectionPanel', () => {
  it('shows only the panel of the active entry once the navigation is offered', async () => {
    const wrapper = mountPage(THREE_SECTIONS);

    expect(shownSections(wrapper)).toEqual(['general']);

    await openSection(wrapper, 'details');

    expect(activeSection(wrapper)).toBe('details');
    expect(shownSections(wrapper)).toEqual(['details']);

    wrapper.unmount();
  });

  it('stacks every panel when the navigation is not offered', () => {
    const wrapper = mountPage([]);

    expect(shownSections(wrapper)).toEqual(['general', 'details', 'history']);

    wrapper.unmount();
  });

  it('renders the shared actions at the end of the visible card unless a panel opts out', async () => {
    const wrapper = mountPage(THREE_SECTIONS);

    expect(panelSaves(wrapper)).toEqual({
      general: 1,
      details: 0,
      history: 0,
    });

    const general = wrapper.get('[data-section-panel="general"]');
    const content = general.get('[data-slot="card-content"]');

    expect(general.attributes('data-slot')).toBe('card');
    expect(content.element.lastElementChild?.hasAttribute('data-form-save')).toBe(true);

    await openSection(wrapper, 'details');

    expect(panelSaves(wrapper)).toEqual({
      general: 0,
      details: 1,
      history: 0,
    });

    await openSection(wrapper, 'history');

    expect(wrapper.findAll('[data-form-save]')).toHaveLength(0);

    wrapper.unmount();
  });

  it('renders the shared actions once, in the last stacked panel, without a navigation', async () => {
    const wrapper = mountPage([], 'general', false);

    await nextTick();

    expect(panelSaves(wrapper)).toEqual({ general: 0, details: 1 });

    wrapper.unmount();
  });

  it('tells its content whether the panel is shown so mount-sensitive content can wait', async () => {
    const Page = defineComponent({
      components: { SectionNav, SectionPanel },
      setup() {
        return { active: ref<string>('general') };
      },
      template: `
                <SectionNav v-model="active" :entries="entries" label="Abschnitte">
                    <SectionPanel section="general" />
                    <SectionPanel section="details" />
                    <SectionPanel v-slot="{ shown }" section="history">
                        <canvas v-if="shown" data-canvas />
                    </SectionPanel>
                </SectionNav>
            `,
      data: () => ({ entries: THREE_SECTIONS }),
    });
    const wrapper = mount(Page);

    expect(wrapper.find('[data-canvas]').exists()).toBe(false);

    await openSection(wrapper, 'history');

    expect(wrapper.find('[data-canvas]').exists()).toBe(true);
  });

  it('opens the panel that holds an invalid native field when the form is checked', async () => {
    const wrapper = mountPage(THREE_SECTIONS);

    wrapper.get<HTMLInputElement>('[data-name-field]').element.value = 'Kunde';

    expect(wrapper.get('form').element.checkValidity()).toBe(false);
    await nextTick();
    await nextTick();

    expect(activeSection(wrapper)).toBe('details');
    expect(shownSections(wrapper)).toEqual(['details']);

    wrapper.unmount();
  });

  it('stays on the active panel when it holds an invalid native field itself', async () => {
    const wrapper = mountPage(THREE_SECTIONS, 'details');

    expect(wrapper.get('form').element.checkValidity()).toBe(false);
    await nextTick();
    await nextTick();

    expect(activeSection(wrapper)).toBe('details');

    wrapper.unmount();
  });
});
