import { describe, expect, it } from 'vitest';
import { emptyCandidateCircle } from './candidateCircle';

describe('emptyCandidateCircle', () => {
  it('starts a circle without any source', () => {
    expect(emptyCandidateCircle()).toEqual({
      sources: [],
      role_ids: [],
      team_ids: [],
      include_record_team: false,
      field_key: null,
      user_ids: [],
    });
  });

  it('hands out a fresh circle every time', () => {
    expect(emptyCandidateCircle()).not.toBe(emptyCandidateCircle());
  });
});
