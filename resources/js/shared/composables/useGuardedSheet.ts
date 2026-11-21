import { ref } from 'vue';
import type { Ref } from 'vue';

export interface UseGuardedSheetOptions {
  isDirty: Readonly<Ref<boolean>>;
  confirmLeave: () => void;
  cancelLeave: () => void;
  close: () => void;
}

export interface UseGuardedSheetReturn {
  closeRequested: Ref<boolean>;
  onCancel: () => void;
  onConfirmLeave: () => void;
  onCancelLeave: () => void;
  onOpenChange: (next: boolean) => void;
}

export function useGuardedSheet(options: UseGuardedSheetOptions): UseGuardedSheetReturn {
  const closeRequested = ref<boolean>(false);

  function onCancel(): void {
    if (options.isDirty.value) {
      closeRequested.value = true;

      return;
    }

    options.close();
  }

  function onConfirmLeave(): void {
    if (closeRequested.value) {
      closeRequested.value = false;
      options.close();

      return;
    }

    options.confirmLeave();
  }

  function onCancelLeave(): void {
    closeRequested.value = false;
    options.cancelLeave();
  }

  function onOpenChange(next: boolean): void {
    if (!next) {
      onCancel();
    }
  }

  return {
    closeRequested,
    onCancel,
    onConfirmLeave,
    onCancelLeave,
    onOpenChange,
  };
}
