<script setup lang="ts">
import { computed } from 'vue';
import FormActions from '@/shared/ui/FormActions.vue';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/primitives/dialog';

export type FormDialogSize = 'md' | 'lg' | 'xl' | '2xl' | '3xl';

const sizeClasses: Record<FormDialogSize, string> = {
  md: 'sm:max-w-md',
  lg: 'sm:max-w-lg',
  xl: 'sm:max-w-xl',
  '2xl': 'sm:max-w-2xl',
  '3xl': 'sm:max-w-3xl',
};

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    dirty: boolean;
    processing?: boolean;
    size?: FormDialogSize;
    scrollable?: boolean;
  }>(),
  {
    description: undefined,
    processing: false,
    size: 'lg',
    scrollable: false,
  },
);

defineOptions({ inheritAttrs: false });

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
  save: [];
  cancel: [];
}>();

const contentClass = computed<string>(() =>
  [sizeClasses[props.size], props.scrollable ? 'max-h-screen overflow-y-auto' : ''].join(' ').trim(),
);

function submit(): void {
  if (!props.dirty || props.processing) {
    return;
  }

  emit('save');
}

function onSubmit(event: Event): void {
  if (event instanceof SubmitEvent && event.submitter instanceof HTMLElement) {
    return;
  }

  submit();
}

function cancel(): void {
  emit('cancel');
  open.value = false;
}

function onOpenChange(value: boolean): void {
  if (!value) {
    cancel();
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="onOpenChange">
    <DialogContent v-bind="$attrs" :class="contentClass" data-form-dialog>
      <DialogHeader>
        <DialogTitle>{{ props.title }}</DialogTitle>
        <DialogDescription v-if="props.description !== undefined || $slots.description !== undefined">
          <slot name="description">{{ props.description }}</slot>
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" data-form-dialog-form @submit.prevent="onSubmit">
        <div class="flex flex-col gap-4">
          <slot />
        </div>

        <DialogFooter>
          <FormActions :dirty="props.dirty" :processing="props.processing" @save="submit" @cancel="cancel" />
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
