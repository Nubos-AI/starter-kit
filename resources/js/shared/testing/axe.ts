import type { RunOptions } from 'axe-core';
import { expect } from 'vitest';
import { axe } from 'vitest-axe';

export const axeOptions: RunOptions = {
  runOnly: {
    type: 'tag',
    values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
  },
  rules: { 'color-contrast': { enabled: false } },
};

export async function expectNoAxeViolations(element: Element): Promise<void> {
  const results = await axe(element, axeOptions);

  expect(
    results.violations.map(
      (violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
    ),
  ).toEqual([]);
}
