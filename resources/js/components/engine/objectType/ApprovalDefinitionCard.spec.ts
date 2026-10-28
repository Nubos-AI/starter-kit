import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ApprovalDefinitionCard from '@/components/engine/objectType/ApprovalDefinitionCard.vue';
import FormActions from '@/components/FormActions.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { selectStubs } from '@/tests/selectStubs';
import type {
    ApprovalAnchorKey,
    ApprovalDefinitionFormValue,
    ApprovalStageValue,
    CandidateCircleValue,
} from '@/types/approvalDefinitions';
import type { SelectOption } from '@/types/ui';

const cardInertia = vi.hoisted(() => ({
    on: vi.fn(() => vi.fn()),
    visit: vi.fn(),
    destroy: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: cardInertia.on,
        visit: cardInertia.visit,
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: cardInertia.destroy,
    },
}));

function emptyCircle(): CandidateCircleValue {
    return {
        sources: [],
        role_ids: [],
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
        deadline_hours: 24,
        escalation_type: null,
        escalation_sources: null,
        candidate_sources: emptyCircle(),
        ...overrides,
    };
}

function definitionValue(
    overrides: Partial<ApprovalDefinitionFormValue> = {},
): ApprovalDefinitionFormValue {
    return {
        is_active: true,
        rejection_stage_transition_id: null,
        exclusions: {
            trigger: true,
            last_editor: true,
            creator: true,
            owner: true,
        },
        stages: [stage()],
        ...overrides,
    };
}

const roleOptions: SelectOption[] = [
    { value: 'role-lead', label: 'Teamleitung' },
];
const teamOptions: SelectOption[] = [
    { value: 'team-sales', label: 'Vertrieb' },
];
const userOptions: SelectOption[] = [{ value: 'user-1', label: 'Rita Rossi' }];
const fieldOptions: SelectOption[] = [
    { value: 'owner_id', label: 'Eigentümer' },
];
const rejectionEdgeOptions: SelectOption[] = [
    { value: 'edge-reject', label: 'Genehmigt → Entwurf' },
];

const SwitchStub = {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" :data-checked="modelValue" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

const CandidateCircleEditorStub = {
    name: 'CandidateCircleEditor',
    props: [
        'modelValue',
        'roleOptions',
        'teamOptions',
        'userOptions',
        'fieldOptions',
    ],
    emits: ['update:modelValue'],
    template: '<div data-candidate-circle-stub />',
};

const cardStubs = {
    ...selectStubs,
    Switch: SwitchStub,
    CandidateCircleEditor: CandidateCircleEditorStub,
};

interface CardMountOptions {
    modelValue?: ApprovalDefinitionFormValue;
    warnings?: string[];
    existing?: boolean;
}

function mountCard(options: CardMountOptions = {}): VueWrapper {
    return mount(ApprovalDefinitionCard, {
        props: {
            modelValue: options.modelValue ?? definitionValue(),
            warnings: options.warnings ?? [],
            roleOptions,
            teamOptions,
            userOptions,
            fieldOptions,
            rejectionEdgeOptions,
            objectTypeSlug: 'deals',
            removalUrl:
                '/pipelines/example/transitions/01TRANSITION0000000000001/approval',
            transitionId: '01TRANSITION0000000000001',
            existing: options.existing ?? false,
        },
        global: { stubs: cardStubs },
    });
}

function lastEmittedDefinition(
    wrapper: VueWrapper,
): ApprovalDefinitionFormValue {
    const events = wrapper.emitted('update:modelValue') as
        | [ApprovalDefinitionFormValue][]
        | undefined;

    if (events === undefined) {
        throw new Error('no update:modelValue emitted');
    }

    return events[events.length - 1][0];
}

function stageRows(wrapper: VueWrapper) {
    return wrapper.findAll('[data-stage]');
}

async function openRemoval(wrapper: VueWrapper) {
    await wrapper.get('[data-approval-delete]').trigger('click');
    await nextTick();

    const dialog = wrapper
        .findAllComponents(ConfirmDialog)
        .find((candidate) => candidate.props('open') === true);

    if (dialog === undefined) {
        throw new Error('the removal confirmation never opened');
    }

    return dialog;
}

beforeEach(() => {
    cardInertia.destroy.mockReset();
    cardInertia.visit.mockReset();
});

describe('ApprovalDefinitionCard — stages can be added, removed and reordered', () => {
    it('adds and reorders stages', async () => {
        const wrapper = mountCard({
            modelValue: definitionValue({
                stages: [
                    stage({ deadline_hours: 24 }),
                    stage({ deadline_hours: 48 }),
                ],
            }),
        });

        expect(stageRows(wrapper)).toHaveLength(2);

        await wrapper.get('[aria-label="Stufe 2 nach oben"]').trigger('click');

        expect(
            lastEmittedDefinition(wrapper).stages.map((s) => s.deadline_hours),
        ).toEqual([48, 24]);

        await wrapper.get('[data-stage-add]').trigger('click');

        expect(lastEmittedDefinition(wrapper).stages).toHaveLength(3);
    });

    it('removes a stage', async () => {
        const wrapper = mountCard({
            modelValue: definitionValue({
                stages: [
                    stage({ deadline_hours: 24 }),
                    stage({ deadline_hours: 48 }),
                ],
            }),
        });

        await wrapper.get('[data-stage-remove="0"]').trigger('click');

        const remaining = lastEmittedDefinition(wrapper).stages;

        expect(remaining).toHaveLength(1);
        expect(remaining[0].deadline_hours).toBe(48);
    });
});

describe('ApprovalDefinitionCard — warnings', () => {
    it('shows a warning passed via prop in the hint block', () => {
        const wrapper = mountCard({
            warnings: [
                'Diese Stufe hat nur eine mögliche Person und schließt sie zugleich aus.',
            ],
        });

        expect(wrapper.text()).toContain(
            'Diese Stufe hat nur eine mögliche Person und schließt sie zugleich aus.',
        );
    });

    it('renders the warnings as a shared alert', () => {
        const wrapper = mountCard({
            warnings: ['Stufe 1 bliebe ohne Genehmiger.'],
        });

        expect(
            wrapper.get('[data-approval-warnings]').attributes('data-slot'),
        ).toBe('alert');
    });

    it('renders no warning block when there is nothing to warn about', () => {
        const wrapper = mountCard({ warnings: [] });

        expect(wrapper.find('[data-approval-warnings]').exists()).toBe(false);
    });
});

describe('ApprovalDefinitionCard — deactivating keeps the stage state', () => {
    it('does not clear stages when is_active is switched off', async () => {
        const wrapper = mountCard({
            modelValue: definitionValue({
                is_active: true,
                stages: [
                    stage({ deadline_hours: 24 }),
                    stage({ deadline_hours: 48 }),
                ],
            }),
        });

        await wrapper.get('[data-approval-active-switch]').trigger('click');

        const updated = lastEmittedDefinition(wrapper);

        expect(updated.is_active).toBe(false);
        expect(updated.stages).toHaveLength(2);
        expect(updated.stages.map((s) => s.deadline_hours)).toEqual([24, 48]);
    });
});

describe('ApprovalDefinitionCard — inside a save/cancel action row', () => {
    const TestHost = defineComponent({
        components: { ApprovalDefinitionCard, FormActions },
        props: {
            initial: { type: Object, required: true },
        },
        setup(props) {
            const value = ref(
                JSON.parse(
                    JSON.stringify(props.initial),
                ) as ApprovalDefinitionFormValue,
            );

            const { isDirty, markSaved } = useUnsavedChanges({
                values: () => value.value,
                backHref: () => '/',
            });

            return { value, isDirty, markSaved };
        },
        template: `
            <div>
                <ApprovalDefinitionCard
                    v-model="value"
                    :warnings="[]"
                    :role-options="[]"
                    :team-options="[]"
                    :user-options="[]"
                    :field-options="[]"
                    :rejection-edge-options="[]"
                    object-type-slug="deals"
                    transition-id="01TRANSITION0000000000001"
                />
                <FormActions :dirty="isDirty" :processing="false" @save="markSaved" @cancel="() => {}" />
            </div>
        `,
    });

    function mountHost(): VueWrapper {
        return mount(TestHost, {
            props: { initial: definitionValue() },
            global: { stubs: cardStubs },
        });
    }

    function isSaveDisabled(wrapper: VueWrapper): boolean {
        return (
            wrapper.get('[data-form-save]').attributes('disabled') !== undefined
        );
    }

    it('keeps saving disabled until a stage changes', () => {
        const wrapper = mountHost();

        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('enables saving after a stage-only change', async () => {
        const wrapper = mountHost();

        await wrapper.get('[data-stage-add]').trigger('click');
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(false);
    });
});

describe('ApprovalDefinitionCard — removing an existing definition', () => {
    it('offers the removal action only for an already stored definition', () => {
        expect(
            mountCard({ existing: false })
                .find('[data-approval-delete]')
                .exists(),
        ).toBe(false);
        expect(
            mountCard({ existing: true })
                .find('[data-approval-delete]')
                .exists(),
        ).toBe(true);
    });

    it('deletes the definition only after the confirmation', async () => {
        const wrapper = mountCard({ existing: true });

        const dialog = await openRemoval(wrapper);

        expect(dialog.props('variant')).toBe('destructive');
        expect(cardInertia.destroy).not.toHaveBeenCalled();

        dialog.vm.$emit('confirm');
        await nextTick();

        expect(cardInertia.destroy).toHaveBeenCalledOnce();
        expect(cardInertia.destroy.mock.calls[0][0]).toContain(
            '01TRANSITION0000000000001',
        );
    });
});

const AnchorCandidateCircleEditorStub = {
    name: 'CandidateCircleEditor',
    props: [
        'modelValue',
        'roleOptions',
        'teamOptions',
        'userOptions',
        'fieldOptions',
        'anchorMode',
    ],
    emits: ['update:modelValue'],
    template: '<div data-candidate-circle-stub />',
};

const anchorCardStubs = {
    ...selectStubs,
    Switch: SwitchStub,
    CandidateCircleEditor: AnchorCandidateCircleEditorStub,
};

function mountAnchorCard(
    options: { existing?: boolean; anchorKind?: ApprovalAnchorKey } = {},
): VueWrapper {
    return mount(ApprovalDefinitionCard, {
        props: {
            modelValue: definitionValue(),
            warnings: [],
            roleOptions,
            teamOptions,
            userOptions,
            anchorKind: options.anchorKind ?? 'promotion',
            existing: options.existing ?? false,
        },
        global: { stubs: anchorCardStubs },
    });
}

describe('ApprovalDefinitionCard — anchor mode without a stage transition', () => {
    it('mounts without an object type, a transition or rejection edges', () => {
        const wrapper = mountAnchorCard();

        expect(stageRows(wrapper)).toHaveLength(1);
        expect(wrapper.find('[data-candidate-circle-stub]').exists()).toBe(
            true,
        );
    });

    it('drops the rejection edge that the transition mode still shows', () => {
        expect(mountCard().text()).toContain('Kante bei Ablehnung');
        expect(mountAnchorCard().text()).not.toContain('Kante bei Ablehnung');
    });

    it('offers no record field as a candidate source', () => {
        const editor = mountAnchorCard().findComponent(
            AnchorCandidateCircleEditorStub,
        );

        expect(editor.props('anchorMode')).toBe(true);
        expect(editor.props('fieldOptions')).toEqual([]);
    });

    it('keeps the field sources available in the transition mode', () => {
        const editor = mountCard().findComponent(CandidateCircleEditorStub);

        expect(editor.props('fieldOptions')).toEqual(fieldOptions);
    });

    it('names the anchor kind in the title and the description of the card', () => {
        const promotion = mountAnchorCard({ anchorKind: 'promotion' }).text();

        expect(promotion).toContain('Genehmigung für Promotionen');
        expect(promotion).toContain(
            'wird eine Promotion erst ausgeführt, wenn alle Stufen zugestimmt haben.',
        );
    });

    it('keeps the transition copy out of the anchor card', () => {
        expect(mountCard().text()).toContain(
            'findet dieser Übergang erst statt, wenn alle Stufen zugestimmt haben.',
        );

        expect(
            mountAnchorCard({ anchorKind: 'promotion' }).text(),
        ).not.toContain('findet dieser Übergang erst statt');
    });

    it('describes the removal card for an anchor instead of a transition', () => {
        const anchorText = mountAnchorCard({ existing: true }).text();

        expect(anchorText).toContain(
            'Nach dem Entfernen ist dieser Vorgang wieder ohne Genehmigung möglich.',
        );
        expect(anchorText).not.toContain(
            'Nach dem Entfernen ist dieser Übergang wieder ohne Genehmigung möglich.',
        );
        expect(mountCard({ existing: true }).text()).toContain(
            'Nach dem Entfernen ist dieser Übergang wieder ohne Genehmigung möglich.',
        );
    });

    it('confirms the removal with the wording of the mode it runs in', async () => {
        const anchorDescription = String(
            (await openRemoval(mountAnchorCard({ existing: true }))).props(
                'description',
            ),
        );
        const transitionDescription = String(
            (await openRemoval(mountCard({ existing: true }))).props(
                'description',
            ),
        );

        expect(anchorDescription).toContain(
            'Möchten Sie die Genehmigung für diesen Vorgang wirklich entfernen?',
        );
        expect(anchorDescription).toContain(
            'Bereits laufende Vorgänge laufen weiter zu Ende',
        );
        expect(anchorDescription).not.toContain('dieses Übergangs');

        expect(transitionDescription).toContain(
            'Möchten Sie die Genehmigung dieses Übergangs wirklich entfernen?',
        );
        expect(transitionDescription).not.toContain('für diesen Vorgang');
    });

    it('offers no exclusion switches in anchor mode because the strict set is fixed', () => {
        expect(mountCard().findAll('[data-approval-exclusion]')).toHaveLength(
            4,
        );
        expect(
            mountAnchorCard().findAll('[data-approval-exclusion]'),
        ).toHaveLength(0);
        expect(mountAnchorCard().text()).not.toContain('Ausschlussgründe');
    });

    it('still edits the stages in anchor mode', async () => {
        const wrapper = mountAnchorCard();

        await wrapper.get('[data-stage-add]').trigger('click');

        expect(lastEmittedDefinition(wrapper).stages).toHaveLength(2);
    });

    it('deletes through the anchor route instead of a transition route', async () => {
        const wrapper = mountAnchorCard({ existing: true });

        const dialog = await openRemoval(wrapper);

        dialog.vm.$emit('confirm');
        await nextTick();

        expect(cardInertia.destroy).toHaveBeenCalledOnce();

        const url = String(cardInertia.destroy.mock.calls[0][0]);

        expect(url).toContain('promotion');
        expect(url).not.toContain('01TRANSITION0000000000001');
    });
});
