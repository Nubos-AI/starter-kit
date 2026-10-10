<script setup lang="ts">
import { useId } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    name: string;
    label: string;
    description?: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ required: true });

const controlId = useId();
</script>

<template>
    <div class="grid gap-1">
        <div class="flex items-center gap-2">
            <input
                type="hidden"
                :name="props.name"
                :value="model ? '1' : '0'"
            />
            <Checkbox
                :id="controlId"
                v-model="model"
                :disabled="props.disabled"
                :data-testid="`flag-${props.name}`"
            />
            <Label :for="controlId" class="font-normal">
                {{ props.label }}
            </Label>
        </div>
        <p v-if="props.description" class="pl-6 text-xs text-muted-foreground">
            {{ props.description }}
        </p>
    </div>
</template>
