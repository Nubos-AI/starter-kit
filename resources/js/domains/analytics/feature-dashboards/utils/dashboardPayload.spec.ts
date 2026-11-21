import { describe, expect, it } from 'vitest';
import { dashboardPayload } from './dashboardPayload';

describe('dashboardPayload', () => {
  it('sends the name and the description', () => {
    expect(dashboardPayload('Vertrieb', 'Zahlen')).toEqual({
      name: 'Vertrieb',
      description: 'Zahlen',
    });
  });

  it('sends a blank description as null', () => {
    expect(dashboardPayload('Vertrieb', '   ')).toEqual({
      name: 'Vertrieb',
      description: null,
    });
  });
});
