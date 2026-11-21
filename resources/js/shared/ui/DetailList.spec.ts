import { render } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import DetailList from '@/shared/ui/DetailList.vue';

describe('DetailList', () => {
  it('passes data attributes through to the list', () => {
    const { container } = render(DetailList, { attrs: { 'data-discount-summary': '' } });

    expect(container.querySelector('dl[data-discount-summary]')).not.toBeNull();
  });
});
