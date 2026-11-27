<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue';
import InputError from '@/shared/ui/InputError.vue';
import { Checkbox } from '@/shared/ui/primitives/checkbox';
import { Label } from '@/shared/ui/primitives/label';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
  label: string;
  id?: string;
  name?: string;
  description?: string;
  error?: string;
  disabled?: boolean;
  align?: 'center' | 'start';
}>();

const model = defineModel<boolean>({ required: true });

const attrs = useAttrs();

const generatedId = useId();

const controlId = computed<string>(() => props.id ?? generatedId);

const errorId = computed<string | undefined>(() => (props.error ? `${controlId.value}-error` : undefined));

const alignment = computed<'center' | 'start'>(() => props.align ?? 'center');

function checkboxAttrs(): Record<string, unknown> {
  return Object.fromEntries(Object.entries(attrs).filter(([key]) => key !== 'class'));
}
</script>

<template>
  <div class="grid gap-1" :class="attrs.class" data-form-checkbox>
    <div
      class="flex gap-2"
      :class="alignment === 'start' ? 'items-start' : 'items-center'"
      :data-align="alignment"
      data-form-checkbox-row
    >
      <input v-if="props.name" type="hidden" :name="props.name" :value="model ? '1' : '0'" />
      <Checkbox
        :id="controlId"
        v-model="model"
        :disabled="props.disabled"
        :data-testid="props.name ? `flag-${props.name}` : undefined"
        :aria-describedby="errorId"
        v-bind="checkboxAttrs()"
      />
      <Label :for="controlId" class="font-normal">
        {{ props.label }}
      </Label>
    </div>
    <p v-if="props.description" class="pl-6 text-xs text-muted-foreground" data-form-checkbox-description>
      {{ props.description }}
    </p>
    <InputError :id="errorId" :message="props.error" />
  </div>
</template>
