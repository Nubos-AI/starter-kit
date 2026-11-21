import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useSectionDefinition, useSectionTabs } from './useSectionTabs';

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({
    props: {
      i18n: {
        locale: 'de',
        fallbackLocale: 'en',
        messages: {
          pages: { sections: { general: 'Allgemein' } },
        },
      },
    },
  }),
}));

describe('section tabs', () => {
  it('flags the section whose field matches an error most precisely', () => {
    const errors = ref<Record<string, string>>({
      'hosts.1.availability_schedule_id': 'Bitte wählen Sie einen Zeitplan.',
    });
    const { sections } = useSectionTabs(
      () => [
        { key: 'team', title: 'Team', fields: ['hosts'] },
        {
          key: 'availability',
          title: 'Verfügbarkeit',
          fields: ['hosts.*.availability_schedule_id'],
        },
        { key: 'share', title: 'Teilen' },
      ],
      () => errors.value,
    );

    expect(sections.value.map((section) => [section.key, section.hasErrors])).toEqual([
      ['team', false],
      ['availability', true],
      ['share', false],
    ]);

    errors.value = { 'hosts.0.user_id': 'Ungültig.' };

    expect(sections.value.map((section) => [section.key, section.hasErrors])).toEqual([
      ['team', true],
      ['availability', false],
      ['share', false],
    ]);
  });

  it('opens the first errored section unless the current one has errors itself', () => {
    const errors = ref<Record<string, string>>({});
    const { active, revealErrors } = useSectionTabs(
      () => [
        { key: 'general', title: 'Allgemein', fields: ['title'] },
        { key: 'place', title: 'Ort', fields: ['locations'] },
        { key: 'notice', title: 'Hinweise', fields: ['notifications'] },
      ],
      () => errors.value,
    );

    errors.value = {
      'locations.0.detail': 'Fehlt.',
      notifications: 'Fehlt.',
    };
    revealErrors();

    expect(active.value).toBe('place');

    active.value = 'notice';
    revealErrors();

    expect(active.value).toBe('notice');
  });

  it('offers a side navigation from three sections on', () => {
    const state = useSectionTabs(
      () => [
        { key: 'details', title: 'Details' },
        { key: 'sets', title: 'Sets' },
        { key: 'archive', title: 'Archiv' },
      ],
      () => ({}),
    );

    expect(state.active.value).toBe('details');
    expect(state.sections.value.map((entry) => entry.key)).toEqual(['details', 'sets', 'archive']);
  });

  it('stacks one or two sections without a navigation', () => {
    const keys = ref<string[]>(['details']);
    const state = useSectionTabs(
      () => keys.value.map((key) => ({ key, title: key })),
      () => ({}),
    );

    expect(state.sections.value).toEqual([]);

    keys.value = ['details', 'archive'];

    expect(state.sections.value).toEqual([]);

    keys.value = ['details', 'sets', 'archive'];

    expect(state.sections.value).toHaveLength(3);
  });

  it('titles a section from the translation prefix and keeps its fields', () => {
    const section = useSectionDefinition('pages.sections');

    expect(section('general', ['name'])).toEqual({
      key: 'general',
      title: 'Allgemein',
      fields: ['name'],
    });
    expect(section('general')).toEqual({
      key: 'general',
      title: 'Allgemein',
      fields: [],
    });
  });
});
