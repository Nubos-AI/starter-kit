// @vitest-environment node

import { describe, expect, it } from 'vitest';
import { createSSRApp, defineComponent, h, useTemplateRef } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { useTopEdgeReveal } from './useTopEdgeReveal';

const Header = defineComponent({
  setup() {
    const header = useTemplateRef<HTMLElement>('header');
    const { isHidden } = useTopEdgeReveal(header);

    return () =>
      h('header', {
        ref: 'header',
        'data-hidden': String(isHidden.value),
      });
  },
});

describe('top edge reveal on the server', () => {
  it('renders the header without touching window or document', async () => {
    const html = await renderToString(createSSRApp(Header));

    expect(html).toContain('data-hidden="false"');
  });
});
