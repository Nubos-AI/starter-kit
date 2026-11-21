import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import { selectStubs } from '@/shared/testing/selectStubs';
import type { FieldDefinition } from '@/shared/types/fields';
import type { TriggerState } from './RuleTriggerConfig.vue';
import RuleTriggerConfig from './RuleTriggerConfig.vue';

const fields = [
  { key: 'contract_end', field_type: 'date', label: 'Vertragsende' },
  { key: 'salary', field_type: 'number', label: 'Gehalt' },
] as FieldDefinition[];

function renderTrigger(modelValue: TriggerState, errors: Record<string, string>) {
  return render(RuleTriggerConfig, {
    props: { fields, modelValue, errors },
    global: { stubs: selectStubs },
  });
}

describe('RuleTriggerConfig', () => {
  it('shows the refusal of the date field below the date field', () => {
    renderTrigger(
      {
        triggerType: 'date_based',
        config: { date_field_key: 'contract_end', lead_stages: [] },
      },
      {
        'config.date_field_key': 'Das Datumsfeld steht nicht zur Verfügung.',
      },
    );

    expect(screen.getByText('Das Datumsfeld steht nicht zur Verfügung.')).toBeTruthy();
  });

  it('shows the refusal of the watched fields below the watched fields', () => {
    renderTrigger(
      {
        triggerType: 'field_change',
        config: { watched_field_keys: ['salary'] },
      },
      { 'config.watched_field_keys': 'Ein beobachtetes Feld fehlt.' },
    );

    expect(screen.getByText('Ein beobachtetes Feld fehlt.')).toBeTruthy();
  });
});
