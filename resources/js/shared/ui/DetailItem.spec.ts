import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import DetailItem from '@/shared/ui/DetailItem.vue';

describe('DetailItem', () => {
  it('marks an emphasised summary total', () => {
    const { container } = render(DetailItem, {
      props: { label: 'Brutto', variant: 'summary', emphasized: true },
      slots: { default: '119,00 €' },
    });

    const row = container.querySelector('[data-detail-item="summary"]');

    expect(row?.hasAttribute('data-detail-item-emphasized')).toBe(true);
  });

  it('lets a label slot replace the label text', () => {
    render(DetailItem, {
      props: { variant: 'summary' },
      slots: { label: '<span data-custom-label>Gutschein SOMMER</span>', default: '−5,00 €' },
    });

    expect(screen.getByText('Gutschein SOMMER').hasAttribute('data-custom-label')).toBe(true);
  });

  it('passes data attributes through to the row', () => {
    const { container } = render(DetailItem, {
      props: { label: 'Steuer', variant: 'summary' },
      attrs: { 'data-quote-total': 'tax' },
      slots: { default: '19,00 €' },
    });

    expect(container.querySelector('[data-quote-total="tax"] [data-detail-item-value]')?.textContent?.trim()).toBe(
      '19,00 €',
    );
  });
});
