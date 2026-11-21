import { describe, expect, it } from 'vitest';
import NumberField from '../ui/NumberField.vue';
import { useFieldTypeRegistry } from './useFieldTypeRegistry';

describe('the widget registry hands numbers to the localised number field', () => {
  it.each(['number', 'decimal', 'money'] as const)(
    'resolves %s to the number field without a native input type',
    (type) => {
      const { resolveWidget } = useFieldTypeRegistry();

      expect(resolveWidget(type)).toEqual({ component: NumberField });
    },
  );
});
