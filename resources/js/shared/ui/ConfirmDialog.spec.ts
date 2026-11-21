import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { buttonLabelled } from '@/shared/testing/dom';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

function mountDialog(props: Record<string, unknown> = {}) {
  return mount(ConfirmDialog, {
    props: {
      open: true,
      title: 'Delete role?',
      description: 'This action cannot be undone.',
      ...props,
    },
    global: { stubs },
  });
}

describe('ConfirmDialog', () => {
  it('renders title, description and the default labels', () => {
    const wrapper = mountDialog();

    expect(wrapper.text()).toContain('Delete role?');
    expect(wrapper.text()).toContain('This action cannot be undone.');
    expect(buttonLabelled(wrapper, 'Bestätigen')).toBeDefined();
    expect(buttonLabelled(wrapper, 'Abbrechen')).toBeDefined();
  });

  it('emits confirm when the confirm button is pressed', async () => {
    const wrapper = mountDialog({
      confirmLabel: 'Delete',
      variant: 'destructive',
    });

    await buttonLabelled(wrapper, 'Delete')?.trigger('click');

    expect(wrapper.emitted('confirm')).toHaveLength(1);
  });

  it('emits cancel and closes when the cancel button is pressed', async () => {
    const wrapper = mountDialog();

    await buttonLabelled(wrapper, 'Abbrechen')?.trigger('click');

    expect(wrapper.emitted('cancel')).toHaveLength(1);
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
  });

  it('disables both buttons while pending', () => {
    const wrapper = mountDialog({ confirmLabel: 'Delete', pending: true });

    expect(buttonLabelled(wrapper, 'Delete')?.attributes('disabled')).toBeDefined();
    expect(buttonLabelled(wrapper, 'Abbrechen')?.attributes('disabled')).toBeDefined();
  });
});
