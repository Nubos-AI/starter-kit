import type { CandidateCircleValue } from '../types/approvalDefinitions';

export function emptyCandidateCircle(): CandidateCircleValue {
  return {
    sources: [],
    role_ids: [],
    team_ids: [],
    include_record_team: false,
    field_key: null,
    user_ids: [],
  };
}
