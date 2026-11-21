import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import readOnlyNotice from './readOnlyNotice.js';

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

tester.run('read-only-notice', readOnlyNotice, {
  valid: [
    template('<ReadOnlyNotice v-if="!canUpdate" :reason="updateReason" />'),
    template('<Alert v-if="!isConnected">{{ t(\'reconnect\') }}</Alert>'),
    template('<Alert v-if="canUpdate">{{ t(\'hint\') }}</Alert>'),
    template('<Alert v-if="!candidates.length">{{ t(\'none\') }}</Alert>'),
  ],
  invalid: [
    {
      code: template('<Alert v-if="!canUpdate"><AlertDescription>{{ reason }}</AlertDescription></Alert>'),
      errors: [{ messageId: 'readOnly' }],
    },
    {
      code: template('<Alert v-if="!canManage && manageReason !== null">{{ manageReason }}</Alert>'),
      errors: [{ messageId: 'readOnly' }],
    },
    {
      code: template('<Alert v-if="!props.can_import" data-config-import-reason>{{ reason }}</Alert>'),
      errors: [{ messageId: 'readOnly' }],
    },
    {
      code: template('<p v-if="busy" /><Alert v-else-if="!run.can_update">{{ reason }}</Alert>'),
      errors: [{ messageId: 'readOnly' }],
    },
  ],
});
