<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Api/ApiTokensController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { ObjectTypeAccess } from '@/lib/apiTokenAccess';
import { accessLevelOptions } from '@/lib/apiTokenAccess';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        objectTypeOptions?: SelectOption[];
    }>(),
    {
        objectTypeOptions: () => [],
    },
);

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.api_tokens.form.new_api_token') },
]);

const name = ref<string>('');
const selectedObjectTypes = ref<string[]>([]);
const levelsByObjectType = ref<Record<string, string[]>>({});
const expiresAt = ref<string>('');

const accessRows = computed<SelectOption[]>(() =>
    props.objectTypeOptions.filter((option) =>
        selectedObjectTypes.value.includes(option.value),
    ),
);

const objectTypeAccess = computed<ObjectTypeAccess[]>(() =>
    accessRows.value.map((option) => ({
        objectType: option.value,
        levels: levelsByObjectType.value[option.value] ?? [],
    })),
);

watch(selectedObjectTypes, (selected) => {
    const next: Record<string, string[]> = {};

    selected.forEach((slug) => {
        next[slug] = levelsByObjectType.value[slug] ?? ['read'];
    });

    levelsByObjectType.value = next;
});

function levelsFor(slug: string): string[] {
    return levelsByObjectType.value[slug] ?? [];
}

function setLevels(slug: string, levels: string[]): void {
    levelsByObjectType.value = { ...levelsByObjectType.value, [slug]: levels };
}

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        name: name.value,
        objectTypeAccess: objectTypeAccess.value,
        expiresAt: expiresAt.value,
    }),
    backHref: ApiTokensController.index.url(),
});

function accessError(errors: Record<string, string>): string | undefined {
    const key = Object.keys(errors).find((candidate) =>
        candidate.startsWith('objectTypeAccess'),
    );

    return key === undefined ? undefined : errors[key];
}

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        objectTypeAccess: objectTypeAccess.value,
        expiresAt: expiresAt.value === '' ? null : expiresAt.value,
    };
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="t('i18n.pages.api_tokens.form.new_api_token')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.api_tokens.form.new_api_token')"
            :description="
                t(
                    'i18n.pages.api_tokens.form.the_secret_is_shown_only_once_after_creation_store',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.api_tokens.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.api_tokens.form.this_token_does_not_belong_to_a_person_it',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ApiTokensController.store.form()"
                    :transform="withSelections"
                    :on-success="markSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="api-token-name">{{
                            t('i18n.pages.api_tokens.form.name')
                        }}</Label>
                        <Input
                            id="api-token-name"
                            v-model="name"
                            name="name"
                            type="text"
                            required
                            :placeholder="
                                t(
                                    'i18n.pages.api_tokens.form.e_g_ci_integration',
                                )
                            "
                            data-api-token-name
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="api-token-object-types">{{
                            t('i18n.pages.api_tokens.form.object_types')
                        }}</Label>
                        <MultiSelect
                            id="api-token-object-types"
                            v-model="selectedObjectTypes"
                            :options="props.objectTypeOptions"
                            :placeholder="
                                t(
                                    'i18n.pages.api_tokens.form.select_object_types',
                                )
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.api_tokens.form.no_object_types_available',
                                )
                            "
                            :aria-label="
                                t('i18n.pages.api_tokens.form.object_types')
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.api_tokens.form.only_the_selected_object_types_are_accessible_through_this',
                                )
                            }}
                        </p>
                        <InputError :message="accessError(errors)" />
                    </div>

                    <div
                        v-if="accessRows.length > 0"
                        class="grid gap-3"
                        data-api-token-access-matrix
                    >
                        <div
                            v-for="row in accessRows"
                            :key="row.value"
                            class="grid items-center gap-2 sm:grid-cols-2"
                        >
                            <Label :for="`api-token-levels-${row.value}`">
                                {{ row.label }}
                            </Label>
                            <MultiSelect
                                :id="`api-token-levels-${row.value}`"
                                :model-value="levelsFor(row.value)"
                                :options="accessLevelOptions"
                                :placeholder="
                                    t(
                                        'i18n.pages.api_tokens.form.select_access',
                                    )
                                "
                                :aria-label="
                                    t('i18n.pages.api_tokens.form.access_to', {
                                        value1: row.label,
                                    })
                                "
                                @update:model-value="
                                    setLevels(row.value, $event)
                                "
                            />
                        </div>
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="api-token-expires">{{
                            t('i18n.pages.api_tokens.form.expires')
                        }}</Label>
                        <Input
                            id="api-token-expires"
                            v-model="expiresAt"
                            name="expiresAt"
                            type="datetime-local"
                            data-api-token-expires
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.api_tokens.form.optional_without_a_date_the_token_does_not_expire',
                                )
                            }}
                        </p>
                        <InputError :message="errors.expiresAt" />
                    </div>

                    <FormActions
                        :dirty="isDirty"
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
