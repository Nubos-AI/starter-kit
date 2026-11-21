import { fireEvent, render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import { t } from '@/shared/testing/i18n';
import FormDialog from '@/shared/ui/FormDialog.vue';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: { props: ['open'], emits: ['update:open'], template: '<div v-if="open" data-dialog><slot /></div>' },
  DialogContent: { template: '<div data-dialog-content v-bind="$attrs"><slot /></div>' },
  DialogHeader: passthrough,
  DialogFooter: { template: '<div data-dialog-footer><slot /></div>' },
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

function renderDialog(props: Record<string, unknown> = {}) {
  return render(FormDialog, {
    props: { open: true, title: 'Gutschrift anlegen', dirty: true, ...props },
    slots: { default: '<input data-dialog-field />' },
    global: { stubs },
  });
}

function dialogForm(container: Element): HTMLFormElement {
  const form = container.querySelector('form[data-form-dialog-form]');

  if (!(form instanceof HTMLFormElement)) {
    throw new Error('dialog form missing');
  }

  return form;
}

describe('FormDialog', () => {
  it('emits save when the save button is pressed', async () => {
    const { emitted } = renderDialog();

    await fireEvent.click(screen.getByRole('button', { name: t('i18n.components.form_actions.save') }));

    expect(emitted('save')).toHaveLength(1);
  });

  it('saves once when the form is submitted with enter', async () => {
    const { container, emitted } = renderDialog();

    await fireEvent.submit(dialogForm(container));

    expect(emitted('save')).toHaveLength(1);
  });

  it('saves only once when the save button submits the form', async () => {
    const { container, emitted } = renderDialog();
    const save = container.querySelector('[data-form-save]');

    if (!(save instanceof HTMLButtonElement)) {
      throw new Error('save button missing');
    }

    await fireEvent.click(save);
    await fireEvent(
      dialogForm(container),
      new SubmitEvent('submit', { submitter: save, bubbles: true, cancelable: true }),
    );

    expect(emitted('save')).toHaveLength(1);
  });

  it('does not save when another button inside the dialog submits the form', async () => {
    const { container, emitted } = render(FormDialog, {
      props: { open: true, title: 'Gutschrift anlegen', dirty: true },
      slots: { default: '<button data-add-row>Zeile</button>' },
      global: { stubs },
    });
    const addRow = container.querySelector('[data-add-row]');

    if (!(addRow instanceof HTMLButtonElement)) {
      throw new Error('row button missing');
    }

    await fireEvent(
      dialogForm(container),
      new SubmitEvent('submit', { submitter: addRow, bubbles: true, cancelable: true }),
    );

    expect(emitted('save')).toBeUndefined();
  });

  it('ignores a submit while nothing changed or the request still runs', async () => {
    const { container, emitted, rerender } = renderDialog({ dirty: false });

    await fireEvent.submit(dialogForm(container));

    await rerender({ open: true, title: 'Gutschrift anlegen', dirty: true, processing: true });

    await fireEvent.submit(dialogForm(container));

    expect(emitted('save')).toBeUndefined();
  });

  it('keeps save disabled until something changed and while the request runs', async () => {
    const { container, rerender } = renderDialog({ dirty: false });

    expect(container.querySelector('[data-form-save]')?.hasAttribute('disabled')).toBe(true);

    await rerender({ open: true, title: 'Gutschrift anlegen', dirty: true, processing: true });

    expect(container.querySelector('[data-form-save]')?.hasAttribute('disabled')).toBe(true);

    await rerender({ open: true, title: 'Gutschrift anlegen', dirty: true, processing: false });

    expect(container.querySelector('[data-form-save]')?.hasAttribute('disabled')).toBe(false);
  });

  it('emits cancel and closes when cancel is pressed', async () => {
    const { emitted } = renderDialog();

    await fireEvent.click(screen.getByRole('button', { name: t('i18n.components.form_actions.cancel') }));

    expect(emitted('cancel')).toHaveLength(1);
    expect(emitted('update:open')?.at(-1)).toEqual([false]);
  });

  it('hands its attributes to the dialog content so a page can hook into it', () => {
    const { container } = render(FormDialog, {
      props: { open: true, title: 'Preis anlegen', dirty: true },
      attrs: { 'data-price-dialog': '' },
      global: { stubs },
    });

    expect(container.querySelector('[data-dialog-content]')?.hasAttribute('data-price-dialog')).toBe(true);
  });
});
