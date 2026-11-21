import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UserAvatar from '@/shared/ui/UserAvatar.vue';

function mountAvatar(name: string) {
  return mount(UserAvatar, { props: { name } });
}

describe('UserAvatar', () => {
  it('renders the initials of a full name', () => {
    expect(mountAvatar('Rita Vertrieb').text()).toBe('RV');
  });

  it('renders a single initial for a one-word name', () => {
    expect(mountAvatar('Rita').text()).toBe('R');
  });

  it('exposes the full name as a title', () => {
    expect(mountAvatar('Rita Vertrieb').find('[title]').attributes('title')).toBe('Rita Vertrieb');
  });
});
