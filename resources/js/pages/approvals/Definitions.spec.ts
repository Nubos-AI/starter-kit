import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Definitions from '@/pages/approvals/Definitions.vue';
import { selectStubs } from '@/tests/selectStubs';
import type {
    ApprovalAnchorKey,
    ApprovalDefinitionFormValue,
    ApprovalDefinitionRow,
    ApprovalStageValue,
    CandidateCircleValue,
} from '@/types/approvalDefinitions';
import { setUrlDefaults } from '@/wayfinder';

const definitionsInertia = vi.hoisted(() => ({
    on: vi.fn(() => vi.fn()),
    visit: vi.fn(),
    put: vi.fn(),
    destroy: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: definitionsInertia.on,
        visit: definitionsInertia.visit,
        get: vi.fn(),
        post: vi.fn(),
        put: definitionsInertia.put,
        delete: definitionsInertia.destroy,
    },
    usePage: () => ({
        url: '/nubos/engine/approval-definitions',
        component: 'approvals/Definitions',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

const ApprovalDefinitionCardStub = {
    name: 'ApprovalDefinitionCard',
    props: [
        'modelValue',
        'warnings',
        'roleOptions',
        'teamOptions',
        'userOptions',
        'fieldOptions',
        'rejectionEdgeOptions',
        'objectTypeSlug',
        'transitionId',
        'anchorKind',
        'existing',
        'errors',
    ],
    emits: ['update:modelValue'],
    template: '<div :data-approval-anchor="anchorKind" />',
};

const definitionsStubs = {
    ...selectStubs,
    ApprovalDefinitionCard: ApprovalDefinitionCardStub,
};

function circle(): CandidateCircleValue {
    return {
        sources: ['role'],
        role_ids: ['role-lead'],
        team_ids: [],
        include_record_team: false,
        field_key: null,
        user_ids: [],
    };
}

function stage(
    overrides: Partial<ApprovalStageValue> = {},
): ApprovalStageValue {
    return {
        quorum_type: 'any',
        quorum_count: null,
        deadline_hours: 48,
        escalation_type: null,
        escalation_sources: null,
        candidate_sources: circle(),
        ...overrides,
    };
}

function storedDefinition(
    anchorKind: ApprovalAnchorKey,
): ApprovalDefinitionRow {
    return {
        id: `01APPROVALDEFINITION${anchorKind.toUpperCase()}`,
        stage_transition_id: null,
        anchor_kind: anchorKind,
        is_active: true,
        rejection_stage_transition_id: null,
        exclusions: {
            trigger: true,
            last_editor: true,
            creator: true,
            owner: true,
        },
        stages: [{ ...stage(), position: 1 }],
        updated_at: '2026-09-10T10:00:00+00:00',
    };
}

interface DefinitionsMountOptions {
    promotion?: ApprovalDefinitionRow | null;
    promotionWarnings?: string[];
}

function mountDefinitions(options: DefinitionsMountOptions = {}): VueWrapper {
    return mount(Definitions, {
        props: {
            definitions: {
                promotion:
                    options.promotion === undefined
                        ? storedDefinition('promotion')
                        : options.promotion,
            },
            warnings: {
                promotion: options.promotionWarnings ?? [],
            },
            roleOptions: [{ value: 'role-lead', label: 'Teamleitung' }],
            teamOptions: [{ value: 'team-sales', label: 'Vertrieb' }],
            userOptions: [{ value: 'user-1', label: 'Rita Rossi' }],
        },
        global: { stubs: definitionsStubs },
    });
}

function cards(wrapper: VueWrapper) {
    return wrapper.findAllComponents(ApprovalDefinitionCardStub);
}

function cardFor(wrapper: VueWrapper, anchorKind: ApprovalAnchorKey) {
    const card = cards(wrapper).find(
        (candidate) => candidate.props('anchorKind') === anchorKind,
    );

    if (card === undefined) {
        throw new Error(`the page renders no card for ${anchorKind}`);
    }

    return card;
}

function isSaveDisabled(wrapper: VueWrapper): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') !== undefined;
}

async function changeStageDeadline(
    wrapper: VueWrapper,
    anchorKind: ApprovalAnchorKey,
    deadlineHours: number,
): Promise<ApprovalDefinitionFormValue> {
    const card = cardFor(wrapper, anchorKind);
    const current = card.props('modelValue') as ApprovalDefinitionFormValue;

    const next: ApprovalDefinitionFormValue = {
        ...current,
        stages: [{ ...current.stages[0], deadline_hours: deadlineHours }],
    };

    card.vm.$emit('update:modelValue', next);
    await nextTick();

    return next;
}

async function save(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-form-save]').trigger('click');
    await nextTick();
}

function lastPutOptions(): {
    onError: (received: Record<string, string>) => void;
    onSuccess: () => void;
} {
    const options = definitionsInertia.put.mock.calls[0][2];

    return options as {
        onError: (received: Record<string, string>) => void;
        onSuccess: () => void;
    };
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
    definitionsInertia.put.mockReset();
    definitionsInertia.visit.mockReset();
    definitionsInertia.destroy.mockReset();
});

describe('approvals/Definitions — one card per anchor kind', () => {
    it('shows exactly one card, for promotions', () => {
        const wrapper = mountDefinitions();

        expect(
            wrapper
                .findAll('[data-approval-anchor]')
                .map((card) => card.attributes('data-approval-anchor')),
        ).toEqual(['promotion']);
    });

    it('marks the card as existing only while a definition is stored', () => {
        expect(cardFor(mountDefinitions(), 'promotion').props('existing')).toBe(
            true,
        );
        expect(
            cardFor(mountDefinitions({ promotion: null }), 'promotion').props(
                'existing',
            ),
        ).toBe(false);
    });

    it('seeds the card without a stored definition with the active switch off', () => {
        const wrapper = mountDefinitions({ promotion: null });

        const value = cardFor(wrapper, 'promotion').props(
            'modelValue',
        ) as ApprovalDefinitionFormValue;

        expect(value.is_active).toBe(false);
    });

    it('hands the card its warnings', () => {
        const wrapper = mountDefinitions({
            promotionWarnings: ['Stufe 1 bliebe ohne Genehmiger.'],
        });

        expect(cardFor(wrapper, 'promotion').props('warnings')).toEqual([
            'Stufe 1 bliebe ohne Genehmiger.',
        ]);
    });

    it('carries exactly one action row with one save button', () => {
        const wrapper = mountDefinitions();

        expect(wrapper.findAll('[data-form-actions]')).toHaveLength(1);
        expect(wrapper.findAll('[data-form-save]')).toHaveLength(1);
    });
});

describe('approvals/Definitions — saving', () => {
    it('keeps saving disabled while nothing has changed', () => {
        const wrapper = mountDefinitions();

        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('enables saving after a stage change in the promotion card', async () => {
        const wrapper = mountDefinitions();

        await changeStageDeadline(wrapper, 'promotion', 12);

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('sends the changed promotion definition', async () => {
        const wrapper = mountDefinitions();

        const changed = await changeStageDeadline(wrapper, 'promotion', 12);

        await save(wrapper);

        expect(definitionsInertia.put).toHaveBeenCalledOnce();

        const [url, body] = definitionsInertia.put.mock.calls[0];

        expect(String(url)).toContain('promotion');
        expect(body).toEqual({
            is_active: changed.is_active,
            stages: changed.stages,
        });
        expect(body).not.toHaveProperty('exclusions');
        expect(body).not.toHaveProperty('rejection_stage_transition_id');
    });

    it('sends nothing while saving is disabled', async () => {
        const wrapper = mountDefinitions();

        await save(wrapper);

        expect(definitionsInertia.put).not.toHaveBeenCalled();
    });

    it('locks saving again once the server confirmed the change', async () => {
        const wrapper = mountDefinitions();

        await changeStageDeadline(wrapper, 'promotion', 12);
        await save(wrapper);

        lastPutOptions().onSuccess();
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('routes a server rejection to the stage of the card it belongs to', async () => {
        const wrapper = mountDefinitions();

        await changeStageDeadline(wrapper, 'promotion', 12);
        await save(wrapper);

        lastPutOptions().onError({
            'stages.0.candidate_sources':
                'Ein Kreis möglicher Genehmigender braucht mindestens eine Quelle, die jemanden benennt.',
        });
        await nextTick();

        expect(cardFor(wrapper, 'promotion').props('errors')).toEqual({
            'stages.0.candidate_sources':
                'Ein Kreis möglicher Genehmigender braucht mindestens eine Quelle, die jemanden benennt.',
        });
    });
});
