import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { panelSaves, sectionKeys, shownSections } from '@/shared/testing/sectionNav';
import { comboboxStubs } from '@/shared/testing/selectStubs';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordObjectType, RecordPayload } from '../../../../../shared/business-data/types/records';
import type { MergeCandidates, MergePlan } from '../../../data/types/merge';
import * as recordMergeModule from '../../data/useRecordMerge';
import MergePage from './Merge.vue';

const mergeMocks = vi.hoisted(() => {
  const beforeHandlers: unknown[] = [];

  return {
    visit: vi.fn(),
    beforeHandlers,
    on: vi.fn((event: string, handler: unknown) => {
      if (event === 'before') {
        beforeHandlers.push(handler);
      }

      return vi.fn();
    }),
  };
});

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: { on: mergeMocks.on, visit: mergeMocks.visit },
  usePage: () => ({
    url: '/nubos/records/01RECORD0K5N3Q8V9WYE6M2H7X/merge',
    props: { auth: { user: null, can: {}, authority: null } },
  }),
}));

vi.mock('../../data/useRecordMerge', async () => {
  const { ref } = await import('vue');

  const state = {
    plan: ref(null),
    errors: ref({}),
    previewing: ref(false),
    merging: ref(false),
    preview: vi.fn(),
    merge: vi.fn(),
    onMerged: undefined as unknown as (result: unknown) => void,
  };

  return {
    __mergeState: state,
    useRecordMerge: (onMerged: (result: unknown) => void) => {
      state.onMerged = onMerged;

      return {
        plan: state.plan,
        errors: state.errors,
        previewing: state.previewing,
        merging: state.merging,
        preview: state.preview,
        merge: state.merge,
      };
    },
  };
});

vi.mock('@/shared/composables/useBreadcrumbs', () => ({
  usePageBreadcrumbs: vi.fn(),
}));

const TARGET_ID = '01RECORD0K5N3Q8V9WYE6M2H7X';

const PARTNER_ID = '01PARTNER0K5N3Q8V9WYE6M2H7';

const mergeState = (
  recordMergeModule as unknown as {
    __mergeState: {
      plan: { value: MergePlan | null };
      errors: { value: Record<string, string> };
      previewing: { value: boolean };
      merging: { value: boolean };
      preview: ReturnType<typeof vi.fn>;
      merge: ReturnType<typeof vi.fn>;
      onMerged: (result: unknown) => void;
    };
  }
).__mergeState;

const objectType = {
  id: 'ot-1',
  slug: 'deals',
  name: 'Vorgänge',
} as unknown as RecordObjectType;

const record = {
  id: TARGET_ID,
  objectTypeId: 'ot-1',
  ownerId: null,
  recordNumber: 'REC-1',
  title: 'Vorgang A',
  externalReferenceId: null,
  version: 2,
  data: {},
  createdAt: null,
  updatedAt: null,
} as unknown as RecordPayload;

const candidates: MergeCandidates = {
  suggested_ids: [PARTNER_ID],
  options: [
    { value: 'other-1', label: 'REC-9' },
    {
      value: PARTNER_ID,
      label: 'REC-2',
      description: 'Möglicher Doppeleintrag',
    },
  ],
  is_truncated: false,
};

function plan(overrides: Partial<MergePlan> = {}): MergePlan {
  return {
    target_id: PARTNER_ID,
    source_id: TARGET_ID,
    target_version: 2,
    source_version: 1,
    mode: 'allow',
    rule_id: null,
    rule_name: null,
    deny_reason: null,
    requires_reason: false,
    is_mergeable: true,
    fields: [
      {
        key: 'title',
        label: 'Titel',
        field_type: 'text_short',
        strategy: 'prefer_non_empty',
        target_value: 'A',
        source_value: 'B',
        result_value: 'A',
        origin: 'target',
        is_conflict: true,
        requires_decision: false,
        is_overridden: false,
      },
    ],
    transfers: [{ category: 'notes', policy: 'move', count: 3 }],
    blockers: [],
    ...overrides,
  };
}

function mountMerge(candidateOverrides: Partial<MergeCandidates> = {}): VueWrapper {
  return mount(MergePage, {
    props: {
      objectType,
      record,
      candidates: { ...candidates, ...candidateOverrides },
    },
    global: { stubs: { ...comboboxStubs } },
  });
}

async function choosePartner(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('select.ui-combobox').setValue(PARTNER_ID);
  await flushPromises();
  await nextTick();
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  mergeMocks.visit.mockReset();
  mergeState.preview.mockReset();
  mergeState.merge.mockReset();
  mergeState.merge.mockImplementation(() => {
    mergeState.onMerged({
      merge_id: 'm-1',
      target_id: PARTNER_ID,
      source_id: TARGET_ID,
    });

    return Promise.resolve(null);
  });
  mergeState.plan.value = null;
  mergeState.errors.value = {};
  mergeMocks.beforeHandlers.length = 0;
  mergeState.previewing.value = false;
  mergeState.merging.value = false;
});

describe('records/Merge — choosing the partner', () => {
  it('offers the suggested duplicate before the rest', () => {
    const wrapper = mountMerge();

    expect(
      wrapper.findAll<HTMLOptionElement>('select.ui-combobox option').map((option) => option.element.value),
    ).toEqual([PARTNER_ID, 'other-1']);
  });

  it('shows neither resolution nor summary before a partner is chosen', () => {
    const wrapper = mountMerge();

    expect(wrapper.find('[data-merge-resolution]').exists()).toBe(false);
    expect(wrapper.find('[data-merge-transfers]').exists()).toBe(false);
    expect(wrapper.find('[data-merge-direction]').exists()).toBe(false);
  });

  it('treats the record the merge was started from as the source', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);

    expect(mergeState.preview).toHaveBeenCalledTimes(1);

    const [recordId, payload] = mergeState.preview.mock.calls[0];

    expect(recordId).toBe(TARGET_ID);
    expect(payload.sourceId).toBe(TARGET_ID);
    expect(payload.targetId).toBe(PARTNER_ID);
  });

  it('names both sides so the direction is never a guess', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);

    const target = wrapper.get('[data-merge-target-summary]');
    const source = wrapper.get('[data-merge-source-summary]');

    expect(target.text()).toContain('Ziel');
    expect(target.text()).toContain('REC-2');
    expect(target.text()).toContain('Bleibt bestehen');
    expect(source.text()).toContain('Quelle');
    expect(source.text()).toContain('Vorgang A');
    expect(source.text()).toContain('Papierkorb');
  });

  it('turns the direction around and asks again', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    await wrapper.get('[data-merge-swap]').trigger('click');
    await flushPromises();

    const [, payload] = mergeState.preview.mock.calls[1];

    expect(payload.targetId).toBe(TARGET_ID);
    expect(payload.sourceId).toBe(PARTNER_ID);

    expect(wrapper.get('[data-merge-target-summary]').text()).toContain('Vorgang A');
  });

  it('says when only the most recent records are offered', () => {
    expect(mountMerge({ is_truncated: true }).find('[data-merge-truncated]').exists()).toBe(true);
  });
});

describe('records/Merge — the plan', () => {
  it('shows the resolution and the transfer summary once the plan arrived', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    expect(wrapper.find('[data-merge-resolution]').exists()).toBe(true);
    expect(wrapper.get('[data-merge-transfer="notes"]').text()).toContain('3');
  });

  it('names the rule that decided the plan', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan({ rule_name: 'Standard' });
    await nextTick();

    expect(wrapper.text()).toContain('Standard');
  });

  it('lists the blockers and keeps the save button shut', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan({
      is_mergeable: false,
      blockers: [{ reason: 'rule_forbids', detail: 'Gesperrt.' }],
    });
    await nextTick();

    expect(wrapper.get('[data-merge-blockers]').text()).toContain('Gesperrt.');
    expect(wrapper.get('[data-form-save]').attributes('disabled')).toBeDefined();
  });

  it('explains a blocker that carries no own text', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan({
      is_mergeable: false,
      blockers: [{ reason: 'stale_version', detail: null }],
    });
    await nextTick();

    expect(wrapper.get('[data-merge-blockers]').text()).toContain('zwischenzeitlich geändert');
  });

  it('asks for a reason only when the rule demands one', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    expect(wrapper.find('#merge-reason').exists()).toBe(false);

    mergeState.plan.value = plan({ requires_reason: true });
    await nextTick();

    expect(wrapper.find('#merge-reason').exists()).toBe(true);
  });
});

describe('records/Merge — committing', () => {
  it('sends the chosen override along with the merge', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    await wrapper.get('[data-merge-choose-source="title"]').trigger('click');
    await flushPromises();
    await nextTick();

    await wrapper.get('[data-form-save]').trigger('click');

    const [, payload] = mergeState.merge.mock.calls[0];

    expect(payload.overrides).toEqual({ title: 'source' });
  });

  it('goes to the surviving record once the merge went through', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    await wrapper.get('[data-form-save]').trigger('click');
    await flushPromises();

    expect(mergeMocks.visit).toHaveBeenCalledTimes(1);
    expect(String(mergeMocks.visit.mock.calls[0][0])).toContain(PARTNER_ID);
  });

  it('merges nothing while no partner is chosen', async () => {
    const wrapper = mountMerge();

    await wrapper.get('[data-form-save]').trigger('click');

    expect(mergeState.merge).not.toHaveBeenCalled();
  });
});

describe('records/Merge — leaving and errors', () => {
  type BeforeHandler = (event: { detail: { visit: { method: string; url: string } } }) => unknown;

  function lastBeforeHandler(): BeforeHandler {
    return mergeMocks.beforeHandlers[mergeMocks.beforeHandlers.length - 1] as BeforeHandler;
  }

  const leaveVisit = {
    detail: { visit: { method: 'get', url: '/nubos/records' } },
  };

  it('asks before leaving once a partner was chosen', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);

    expect(lastBeforeHandler()(leaveVisit)).toBe(false);
    await nextTick();

    expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(true);
  });

  it('lets the visit to the surviving record through after the merge', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    await wrapper.get('[data-form-save]').trigger('click');
    await flushPromises();

    expect(lastBeforeHandler()(leaveVisit)).toBeUndefined();
  });

  it('shows a refusal of the chosen partner below the combobox', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.errors.value = {
      targetId: 'Dieser Datensatz kann nicht gewählt werden.',
    };
    await nextTick();

    expect(wrapper.text()).toContain('Dieser Datensatz kann nicht gewählt werden.');
  });
});

describe('records/Merge — section navigation', () => {
  it('offers resolution and summary in the side navigation once the plan arrived', async () => {
    const wrapper = mountMerge();

    expect(sectionKeys(wrapper)).toEqual([]);
    expect(shownSections(wrapper)).toEqual(['partner']);
    expect(panelSaves(wrapper)).toEqual({ partner: 1 });

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    expect(sectionKeys(wrapper)).toEqual(['partner', 'resolution', 'summary']);
    expect(panelSaves(wrapper)).toEqual({
      partner: 1,
      resolution: 0,
      summary: 0,
    });
  });

  it('shows one section at a time and switches on a click in the navigation', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();

    expect(shownSections(wrapper)).toEqual(['partner']);

    await wrapper.get('[data-section-key="summary"]').trigger('click');

    expect(shownSections(wrapper)).toEqual(['summary']);
  });

  it('opens the section of a field the server refused', async () => {
    const wrapper = mountMerge();

    await choosePartner(wrapper);
    mergeState.plan.value = plan();
    await nextTick();
    await wrapper.get('[data-section-key="summary"]').trigger('click');

    mergeState.errors.value = {
      targetId: 'Dieser Datensatz kann nicht gewählt werden.',
    };
    await nextTick();

    expect(shownSections(wrapper)).toEqual(['partner']);
  });
});
