<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { SelectOption } from '@/types/ui';

const props = defineProps<{
    teamId: string;
    field: 'member_ids' | 'role_ids' | 'denied_permission_ids';
    selected: string[];
    options: SelectOption[];
    title: string;
    description: string;
    label: string;
    placeholder: string;
    emptyLabel: string;
}>();

const selection = ref<string[]>([...props.selected]);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => [...selection.value].sort(),
    backHref: TeamsController.index.url(),
});

function onlyThisSection(): Record<string, FormDataConvertible> {
    return { [props.field]: selection.value };
}
</script>

<template>
    <div class="flex flex-col gap-6" data-team-selection-card>
        <Form
            v-bind="TeamsController.update.form({ team: props.teamId })"
            :transform="onlyThisSection"
            :options="{ preserveScroll: true }"
            :on-success="markSaved"
            v-slot="{ errors, processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{ props.title }}</CardTitle>
                    <CardDescription>{{ props.description }}</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label :for="props.field">{{ props.label }}</Label>
                        <MultiSelect
                            :id="props.field"
                            v-model="selection"
                            :options="props.options"
                            :placeholder="props.placeholder"
                            :empty-label="props.emptyLabel"
                            :aria-label="props.label"
                        />
                        <InputError :message="errors[props.field]" />
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </CardContent>
            </Card>
        </Form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
