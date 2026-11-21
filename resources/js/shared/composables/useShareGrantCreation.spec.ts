import { describe, expect, it, vi } from 'vitest';
import { useShareGrantCreation } from './useShareGrantCreation';

describe('useShareGrantCreation', () => {
  it('grants the chosen recipient with the edit flag and resets the flag', async () => {
    const create = vi.fn().mockResolvedValue({ id: 'share-1' });
    const grant = useShareGrantCreation<'user' | 'team'>(create);

    grant.canEdit.value = true;
    await grant.submit('team', 'team-1');

    expect(create).toHaveBeenCalledWith({
      grantee_type: 'team',
      grantee_id: 'team-1',
      can_edit: true,
    });
    expect(grant.canEdit.value).toBe(false);
    expect(grant.submitting.value).toBe(false);
  });

  it('keeps the edit flag when the grant fails', async () => {
    const grant = useShareGrantCreation<'user'>(vi.fn().mockResolvedValue(null));

    grant.canEdit.value = true;
    await grant.submit('user', 'user-1');

    expect(grant.canEdit.value).toBe(true);
  });

  it('sends nothing without a recipient', async () => {
    const create = vi.fn();
    const grant = useShareGrantCreation<'user'>(create);

    await grant.submit('user', null);
    await grant.submit('user', '');

    expect(create).not.toHaveBeenCalled();
  });
});
