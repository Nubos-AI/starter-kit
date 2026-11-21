import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import type {
  MergeFieldStrategyOption,
  MergeRuleRow,
  MergeTransferCategoryOption,
} from '@/domains/business-data/data/types/merge';
import { openSection, panelSaves, sectionKeys, sectionLayouts, shownSections } from '@/shared/testing/sectionNav';
import { selectStubs } from '@/shared/testing/selectStubs';
import Form from './Form.vue';

const inertia = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  visit: vi.fn(),
  page: {
    url: '/nubos/object-types/companies/merge-rules/create',
    props: {},
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: inertia.visit,
    post: inertia.post,
    put: inertia.put,
  },
  usePage: () => inertia.page,
}));

const FilterBuilderStub = defineComponent({
  name: 'FilterBuilder',
  props: {
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: undefined },
    showActions: { type: Boolean, default: true },
  },
  emits: ['update:modelValue'],
  setup() {
    return () => h('div', { 'data-filter-builder-stub': '' });
  },
});

const STRATEGY_OPTIONS: MergeFieldStrategyOption[] = [
  {
    key: 'phone',
    label: 'Telefon',
    field_type: 'text',
    strategies: ['prefer_non_empty', 'prefer_target'],
  },
];

const TRANSFER_CATEGORIES: MergeTransferCategoryOption[] = [
  { value: 'notes', default_policy: 'move', policies: ['move', 'keep'] },
];

function ruleRow(overrides: Partial<MergeRuleRow> = {}): MergeRuleRow {
  return {
    id: '01MERGERULE0000000000001A',
    object_type_id: '01OBJECTTYPE00000000001A',
    name: 'Dubletten zusammenführen',
    mode: 'allow',
    position: 0,
    is_active: true,
    deny_reason: null,
    condition: null,
    field_strategies: {},
    transfer_policy: {},
    options: {},
    can_update: true,
    can_delete: true,
    update_reason: null,
    delete_reason: null,
    updated_at: '2026-11-10T09:00:00.000Z',
    ...overrides,
  };
}

function mountForm(mergeRule: MergeRuleRow | null = ruleRow()): VueWrapper {
  return mount(Form, {
    props: {
      objectType: {
        slug: 'companies',
        name: 'Companies',
        label: 'Firmen',
      },
      mergeRule,
      mergeConditionFields: [],
      mergeFieldStrategyOptions: STRATEGY_OPTIONS,
      mergeTransferCategories: TRANSFER_CATEGORIES,
      mergeActiveRuleLimit: 10,
      activeRuleCount: 1,
    },
    global: {
      stubs: { ...selectStubs, FilterBuilder: FilterBuilderStub },
    },
  });
}

beforeEach(() => {
  inertia.post.mockReset();
  inertia.put.mockReset();
  inertia.visit.mockReset();
});

describe('objectTypes/mergeRules/Form — section navigation', () => {
  it('lists every group of an allowing rule in the side navigation', () => {
    expect(sectionKeys(mountForm())).toEqual(['basics', 'condition', 'strategies', 'transfers', 'options']);
  });

  it('drops the groups a forbidding rule has no use for and stacks the remaining two without a navigation', async () => {
    const wrapper = mountForm(ruleRow({ mode: 'deny', deny_reason: 'Gesperrt' }));

    expect(sectionKeys(wrapper)).toEqual([]);
    expect(shownSections(wrapper)).toEqual(['basics', 'condition']);
    await nextTick();

    expect(panelSaves(wrapper)).toEqual({ basics: 0, condition: 1 });
  });

  it('shows one section at a time and switches on a click in the navigation', async () => {
    const wrapper = mountForm();

    expect(shownSections(wrapper)).toEqual(['basics']);

    await wrapper.get('[data-section-key="transfers"]').trigger('click');

    expect(shownSections(wrapper)).toEqual(['transfers']);
  });

  it('ends the visible section card in the one shared save row', async () => {
    const wrapper = mountForm();

    expect(sectionLayouts(wrapper)).toMatchObject({
      basics: { isCard: true, saves: 1, endsWithSave: true },
      condition: { isCard: true, saves: 0, endsWithSave: false },
      strategies: { isCard: true, saves: 0, endsWithSave: false },
      transfers: { isCard: true, saves: 0, endsWithSave: false },
      options: { isCard: true, saves: 0, endsWithSave: false },
    });

    await openSection(wrapper, 'options');

    expect(panelSaves(wrapper)).toMatchObject({ basics: 0, options: 1 });
  });

  it('saves the whole rule and opens the section of a refused field', async () => {
    const wrapper = mountForm();

    await wrapper.get('#merge-rule-name').setValue('Firmen-Dubletten');
    await wrapper.get('[data-section-panel="basics"] [data-form-save]').trigger('click');

    expect(inertia.put).toHaveBeenCalledTimes(1);

    const options: unknown = inertia.put.mock.calls[0]?.[2];
    const onError: unknown =
      typeof options === 'object' && options !== null ? Reflect.get(options, 'onError') : undefined;

    if (typeof onError !== 'function') {
      throw new Error('The request was sent without an error handler.');
    }

    onError({ transfer_policy: 'Die Übernahme ist ungültig.' });
    await nextTick();

    expect(shownSections(wrapper)).toEqual(['transfers']);
    expect(wrapper.text()).toContain('Die Übernahme ist ungültig.');
  });
});
