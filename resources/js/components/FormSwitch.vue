<script setup lang="ts">
import { computed, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    label: string;
    id?: string;
    description?: string;
    error?: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ required: true });

const generatedId = useId();

const controlId = computed<string>(() => props.id ?? generatedId);
</script>

<template>
    <div class="grid gap-1" data-form-switch>
        <div class="flex items-center gap-3">
            <Label :for="controlId">{{ props.label }}</Label>
            <Switch
                v-bind="$attrs"
                :id="controlId"
                v-model="model"
                :disabled="props.disabled"
            />
        </div>
        <p
            v-if="props.description"
            class="text-xs text-muted-foreground"
            data-form-switch-description
        >
            {{ props.description }}
        </p>
        <InputError :message="props.error" />
    </div>
</template>
