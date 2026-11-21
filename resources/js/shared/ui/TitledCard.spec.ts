import { render } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import TitledCard from '@/shared/ui/TitledCard.vue';

describe('TitledCard', () => {
  it('omits the description when none is given', () => {
    const { container } = render(TitledCard, { props: { title: 'Zahlungsmethoden' } });

    expect(container.querySelector('[data-slot="card-description"]')).toBeNull();
  });

  it('renders a footer only when one is given', () => {
    const { container } = render(TitledCard, { props: { title: 'Zahlungsmethoden' } });

    expect(container.querySelector('[data-slot="card-footer"]')).toBeNull();

    const withFooter = render(TitledCard, {
      props: { title: 'Zahlungsmethoden' },
      slots: { footer: '<button data-card-footer-action>Speichern</button>' },
    });

    expect(withFooter.container.querySelector('[data-slot="card-footer"] [data-card-footer-action]')).not.toBeNull();
  });
});
