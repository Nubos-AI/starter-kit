<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleAlert, Info } from '@lucide/vue';
import { ref } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { ConfigPlaceholdersProps } from '@/types/configBundle';

const { t } = useI18n();

const props = defineProps<ConfigPlaceholdersProps>();

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.config.placeholders.webhook_destinations') },
]);

const targets = ref<string[]>(
    props.requirements.map((requirement) => requirement.current_value),
);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => [...targets.value],
    backHref: ConfigBundleController.index.url(),
});
</script>

<template>
    <Head :title="t('i18n.pages.config.placeholders.webhook_destinations')" />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="t('i18n.pages.config.placeholders.webhook_destinations')"
            :description="
                t(
                    'i18n.pages.config.placeholders.enter_the_destinations_for_webhooks_from_the_archive_in',
                )
            "
        />

        <Alert v-if="!props.can_save" data-placeholder-locked>
            <Info />
            <AlertTitle>{{
                t('i18n.pages.config.placeholders.view_only')
            }}</AlertTitle>
            <AlertDescription>{{ props.save_reason }}</AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.config.placeholders.webhook_destinations_2')
                }}</CardTitle>
                <CardDescription data-placeholder-explanation>
                    {{
                        t(
                            'i18n.pages.config.placeholders.the_archive_deliberately_excludes_webhook_destinations_because_each_tenant',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ConfigBundleController.storePlaceholders.form({
                            run: props.run.id,
                        })
                    "
                    :on-success="markSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <Alert
                        v-if="errors.targets"
                        variant="destructive"
                        data-placeholder-error
                    >
                        <CircleAlert />
                        <AlertDescription>{{
                            errors.targets
                        }}</AlertDescription>
                    </Alert>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="(requirement, index) in props.requirements"
                            :key="requirement.artifact_key"
                            class="grid gap-2"
                            data-placeholder-field
                        >
                            <Label :for="`placeholder-target-${index}`">
                                {{ requirement.label }}
                            </Label>
                            <input
                                type="hidden"
                                :name="`targets[${index}][key]`"
                                :value="requirement.artifact_key"
                            />
                            <Input
                                :id="`placeholder-target-${index}`"
                                v-model="targets[index]"
                                :name="`targets[${index}][target_url]`"
                                inputmode="url"
                                autocomplete="off"
                                :placeholder="
                                    t('i18n.pages.config.placeholders.https')
                                "
                                :disabled="!props.can_save"
                            />
                            <InputError
                                :message="
                                    errors[`targets.${index}.target_url`] ??
                                    errors[`targets.${index}.key`]
                                "
                            />
                        </div>
                    </div>

                    <FormActions
                        :dirty="isDirty && props.can_save"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
