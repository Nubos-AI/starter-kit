import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import SubsectionHeading from '@/shared/ui/SubsectionHeading.vue';

describe('SubsectionHeading', () => {
  it('omits the description when none is given', () => {
    const { container } = render(SubsectionHeading, { props: { title: 'Domainnachweise' } });

    expect(container.querySelector('[data-subsection-heading-description]')).toBeNull();
  });

  it('uses the heading level the content hierarchy asks for', () => {
    render(SubsectionHeading, { props: { title: 'Bestehende Datensätze', level: 3 } });

    expect(screen.getByRole('heading', { level: 3, name: 'Bestehende Datensätze' })).toBeTruthy();
  });
});
