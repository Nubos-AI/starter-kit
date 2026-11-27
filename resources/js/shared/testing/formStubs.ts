import { recordPickerStubs } from './recordPickerStub';
import { comboboxStubs, selectStubs } from './selectStubs';

const passthrough = { template: '<div v-bind="$attrs"><slot /></div>' };

export const dialogStubs = {
  Dialog: {
    props: ['open'],
    template: '<div v-if="open"><slot /></div>',
  },
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

export const tooltipStubs = {
  TooltipProvider: passthrough,
  Tooltip: passthrough,
  TooltipTrigger: passthrough,
  TooltipContent: passthrough,
};

export const checkboxStub = {
  Checkbox: {
    props: ['modelValue', 'id', 'disabled'],
    emits: ['update:modelValue'],
    template:
      '<input type="checkbox" :id="id" :disabled="disabled" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
  },
};

export const formStubs = {
  ...selectStubs,
  ...comboboxStubs,
  ...recordPickerStubs,
  ...dialogStubs,
  ...tooltipStubs,
  ...checkboxStub,
  UnsavedChangesDialog: true,
};
