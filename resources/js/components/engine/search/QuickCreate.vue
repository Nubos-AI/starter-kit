<script setup lang="ts">
import { onMounted } from 'vue';
import DynamicForm from '@/components/DynamicForm.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useQuickCreate } from '@/composables/useQuickCreate';

const { t } = useI18n();

const {
    catalog,
    selectedType,
    values,
    errors,
    saving,
    loadCatalog,
    select,
    submit,
} = useQuickCreate();

onMounted(loadCatalog);
</script>

<template>
    <div data-quick-create>
        <div v-if="selectedType === null" data-quick-create-catalog>
            <p class="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                {{ t('i18n.components.engine.search.quick_create.create') }}
            </p>
            <Button
                v-for="type in catalog"
                :key="type.id"
                type="button"
                variant="ghost"
                data-quick-create-type
                :data-object-type-slug="type.slug"
                class="h-auto w-full cursor-default justify-start rounded-sm px-2 py-1.5 text-left font-normal"
                @click="select(type)"
            >
                {{ type.name }}
            </Button>
        </div>
        <div v-else data-quick-create-form class="flex flex-col gap-6 p-2">
            <DynamicForm
                :fields="selectedType.fieldDefinitions"
                v-model="values"
                :errors="errors"
            />
            <Button
                type="button"
                data-quick-create-save
                :disabled="saving"
                @click="submit"
            >
                {{
                    saving
                        ? t('i18n.components.engine.search.quick_create.saving')
                        : t('i18n.components.engine.search.quick_create.create')
                }}
            </Button>
        </div>
    </div>
</template>
