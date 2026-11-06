export type CandidateSourceKey = 'role' | 'team' | 'field' | 'fixed_list';

export interface CandidateCircleValue {
    sources: CandidateSourceKey[];
    role_ids: string[];
    team_ids: string[];
    include_record_team: boolean;
    field_key: string | null;
    user_ids: string[];
}

export type ApprovalQuorumKey = 'any' | 'at_least_n' | 'all';

export type ApprovalEscalationKey =
    | 'delegate'
    | 'widen_circle'
    | 'notify_again';

export interface ApprovalStageValue {
    quorum_type: ApprovalQuorumKey;
    quorum_count: number | null;
    deadline_hours: number | null;
    escalation_type: ApprovalEscalationKey | null;
    escalation_sources: CandidateCircleValue | null;
    candidate_sources: CandidateCircleValue;
}

export interface ApprovalStageRow extends ApprovalStageValue {
    position: number;
}

export interface ApprovalExclusionValue {
    trigger: boolean;
    last_editor: boolean;
    creator: boolean;
    owner: boolean;
}

export type ApprovalAnchorKey = 'promotion';

export interface ApprovalDefinitionFormValue {
    is_active: boolean;
    rejection_stage_transition_id: string | null;
    exclusions: ApprovalExclusionValue;
    stages: ApprovalStageValue[];
}

export interface ApprovalDefinitionRow extends Omit<
    ApprovalDefinitionFormValue,
    'stages'
> {
    id: string;
    stage_transition_id: string | null;
    anchor_kind: ApprovalAnchorKey | null;
    stages: ApprovalStageRow[];
    updated_at: string | null;
}
