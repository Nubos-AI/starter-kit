import { describe, expect, it } from 'vitest';
import { useFlowCanvas } from '@/shared/composables/useFlowCanvas';

describe('useFlowCanvas — webhook trigger secret', () => {
  it('sends no secret key back while the stored one is untouched and the typed one once it replaces it', () => {
    const canvas = useFlowCanvas();

    canvas.fromDefinition({
      trigger: { type: 'webhook', has_secret: true },
      graph: { entry: '', nodes: [] },
    } as unknown as never);

    const trigger = canvas.toDefinition().trigger;

    expect('secret' in trigger).toBe(false);
    expect('has_secret' in trigger).toBe(false);

    canvas.trigger.value = { ...canvas.trigger.value, secret: 'replaced' };

    expect(canvas.toDefinition().trigger.secret).toBe('replaced');
  });
});
