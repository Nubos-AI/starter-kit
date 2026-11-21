import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import statusBadgeOnly from './statusBadgeOnly.js';

RuleTester.describe = describe;
RuleTester.it = it;
RuleTester.itOnly = it.only;

const tester = new RuleTester({
  languageOptions: {
    parser: vueParser,
    ecmaVersion: 2022,
    sourceType: 'module',
  },
});

function template(markup: string): string {
  return `<template>${markup}</template>`;
}

tester.run('status-badge-only', statusBadgeOnly, {
  valid: [
    template('<StatusBadge :map="INVOICE_STATUS" :status="row.status" :label="row.statusLabel" />'),
    template('<Badge variant="outline">{{ t(\'draft\') }}</Badge>'),
    template("<Badge :variant=\"share.can_edit ? 'default' : 'secondary'\">{{ t('role') }}</Badge>"),
    template('<Badge :variant="tone">{{ t(\'role\') }}</Badge>'),
    template('<Button :variant="buttonVariant(state)">{{ t(\'go\') }}</Button>'),
  ],
  invalid: [
    {
      code: template('<Badge :variant="invoiceStatusVariant(invoice.status)">{{ invoice.statusLabel }}</Badge>'),
      errors: [{ messageId: 'statusBadge' }],
    },
    {
      code: template('<Badge :variant="resolveStatus(statuses, stateOf(link)).variant">{{ t(\'x\') }}</Badge>'),
      errors: [{ messageId: 'statusBadge' }],
    },
    {
      code: template('<Badge v-if="status" :variant="status.variant">{{ status.label }}</Badge>'),
      errors: [{ messageId: 'statusBadge' }],
    },
    {
      code: template('<Badge :variant="statusVariants[order.status] ?? \'outline\'">{{ order.statusLabel }}</Badge>'),
      errors: [{ messageId: 'statusBadge' }],
    },
    {
      code: template('<Badge :variant="badges[row.reason]?.variant">{{ row.reasonLabel }}</Badge>'),
      errors: [{ messageId: 'statusBadge' }],
    },
  ],
});
