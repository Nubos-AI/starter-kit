import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { openSection, sectionKeys, sectionLayouts, shownSections } from '@/shared/testing/sectionNav';
import type { PromotionDiffGroup, PromotionDiffRow, PromotionReport, PromotionReviewRun } from '../../types/promotion';
import Review from './Review.vue';

const { reactivePage } = await vi.hoisted(async () => {
  const { reactive } = await import('vue');

  return {
    reactivePage: reactive({
      url: '/nubos/engine/promotions/01PROMOTIONRUN000000000001',
      props: { errors: {} as Record<string, string> },
    }),
  };
});

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  },
  usePage: () => reactivePage,
}));

const stubs = {
  DataGrid: { template: '<div data-data-grid-stub />' },
};

function reviewRun(): PromotionReviewRun {
  return {
    id: '01PROMOTIONRUN000000000001',
    status: 'draft',
    direction: 'push',
    direction_label: 'Quelle → Live',
    counterpart_label: 'Quelle',
    can_update: true,
    update_reason: null,
    can_submit: true,
    submit_reason: null,
    has_outdated_decisions: false,
    undecided_count: 0,
  };
}

function diffRow(kind: string): PromotionDiffRow {
  return {
    kind,
    key: `${kind}-key`,
    state: 'modified',
    changed_paths: [],
    is_selected: false,
    is_pulled_in: false,
    pulled_in_reason: null,
    refusal_reason: null,
    decision: null,
    can_select: true,
    select_reason: null,
    can_overwrite: false,
    overwrite: false,
    overwrite_consequence: null,
  };
}

function group(kind: string): PromotionDiffGroup {
  return {
    kind,
    kind_label: kind,
    unchanged_count: 0,
    rename_hints: [],
    rows: [diffRow(kind)],
  };
}

function report(): PromotionReport {
  return {
    error: null,
    written_count: 1,
    skipped_count: 0,
    notes: [],
    results: [],
  };
}

function mountReview(withReport = false): VueWrapper {
  return mount(Review, {
    props: {
      run: reviewRun(),
      groups: [group('object-types'), group('roles')],
      decision_options: [],
      source_error: null,
      report: withReport ? report() : null,
    },
    global: { stubs },
  });
}

describe('promotion/Review — section navigation', () => {
  it('lists the result, every artifact group and the approval', () => {
    expect(sectionKeys(mountReview(true))).toEqual(['report', 'kind-object-types', 'kind-roles', 'approval']);
    expect(sectionKeys(mountReview())).toEqual(['kind-object-types', 'kind-roles', 'approval']);
  });

  it('shows one section at a time and switches on a click in the navigation', async () => {
    const wrapper = mountReview();

    expect(shownSections(wrapper)).toEqual(['kind-object-types']);

    await wrapper.get('[data-section-key="approval"]').trigger('click');

    expect(shownSections(wrapper)).toEqual(['approval']);
  });

  it('ends the visible artifact group in the one shared save row and keeps it out of the result and the approval', async () => {
    const wrapper = mountReview(true);

    await openSection(wrapper, 'kind-roles');

    expect(sectionLayouts(wrapper)).toMatchObject({
      report: { isCard: true, saves: 0 },
      'kind-object-types': { isCard: true, saves: 0 },
      'kind-roles': { isCard: true, saves: 1, endsWithSave: true },
      approval: { isCard: true, saves: 0 },
    });

    await openSection(wrapper, 'approval');

    expect(wrapper.find('[data-form-actions]').exists()).toBe(false);
  });

  it('opens the approval once the server refuses the submission', async () => {
    const wrapper = mountReview();

    reactivePage.props.errors = {
      conflicts: 'Bitte lösen Sie zuerst alle Konflikte.',
    };
    await nextTick();

    expect(shownSections(wrapper)).toEqual(['approval']);

    reactivePage.props.errors = {};
  });
});
