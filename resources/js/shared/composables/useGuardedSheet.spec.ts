import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useGuardedSheet } from './useGuardedSheet';

function guardedSheet(dirty: boolean) {
  const close = vi.fn();
  const confirmLeave = vi.fn();
  const cancelLeave = vi.fn();
  const sheet = useGuardedSheet({
    isDirty: ref(dirty),
    confirmLeave,
    cancelLeave,
    close,
  });

  return { sheet, close, confirmLeave, cancelLeave };
}

describe('useGuardedSheet', () => {
  it('closes a clean sheet at once', () => {
    const { sheet, close } = guardedSheet(false);

    sheet.onOpenChange(false);

    expect(close).toHaveBeenCalledOnce();
    expect(sheet.closeRequested.value).toBe(false);
  });

  it('asks before closing a sheet with changes and closes on confirm', () => {
    const { sheet, close, confirmLeave } = guardedSheet(true);

    sheet.onCancel();

    expect(close).not.toHaveBeenCalled();
    expect(sheet.closeRequested.value).toBe(true);

    sheet.onConfirmLeave();

    expect(close).toHaveBeenCalledOnce();
    expect(confirmLeave).not.toHaveBeenCalled();
    expect(sheet.closeRequested.value).toBe(false);
  });

  it('stays open when leaving is cancelled', () => {
    const { sheet, close, cancelLeave } = guardedSheet(true);

    sheet.onCancel();
    sheet.onCancelLeave();

    expect(close).not.toHaveBeenCalled();
    expect(cancelLeave).toHaveBeenCalledOnce();
    expect(sheet.closeRequested.value).toBe(false);
  });

  it('passes a page leave on when no close was requested', () => {
    const { sheet, confirmLeave } = guardedSheet(true);

    sheet.onConfirmLeave();

    expect(confirmLeave).toHaveBeenCalledOnce();
  });

  it('ignores the sheet opening', () => {
    const { sheet, close } = guardedSheet(false);

    sheet.onOpenChange(true);

    expect(close).not.toHaveBeenCalled();
  });
});
