<script setup lang="ts">
import { computed } from 'vue';
import CollapsibleCardTitle from '@/components/CollapsibleCardTitle.vue';
import FieldWrapper from '@/components/fields/FieldWrapper.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useFieldTypeRegistry } from '@/composables/useFieldTypeRegistry';
import type { FieldSection } from '@/lib/fieldGrouping';
import { buildFieldSections } from '@/lib/fieldGrouping';
import { fieldCurrency } from '@/lib/formatFieldValue';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';

const props = withDefaults(
    defineProps<{
        fields: FieldDefinition[];
        creating?: boolean;
        groups?: FieldGroupRow[];
        errors?: Record<string, string>;
        objectTypeId?: string | null;
        hiddenSections?: string[];
    }>(),
    {
        creating: false,
        groups: () => [],
        errors: () => ({}),
        objectTypeId: null,
        hiddenSections: () => [],
    },
);

const values = defineModel<Record<string, unknown>>({
    default: () => ({}),
});

const { resolveWidget } = useFieldTypeRegistry();

const renderableFields = computed(() =>
    props.fields.filter((field) => {
        if (
            props.creating &&
            ['computed', 'rollup'].includes(field.field_type)
        ) {
            return false;
        }

        if (resolveWidget(field.field_type)) {
            return true;
        }

        return false;
    }),
);

const sections = computed<FieldSection[]>(() =>
    buildFieldSections(renderableFields.value, props.groups).filter(
        (section) => !props.hiddenSections.includes(section.id),
    ),
);

const { isOpen, setOpen } = useCollapsedSections(props.objectTypeId);

function sectionOpen(section: FieldSection): boolean {
    if (section.fields.some((field) => props.errors[field.key])) {
        return true;
    }

    return isOpen(section.id);
}

function widgetFor(field: FieldDefinition) {
    return resolveWidget(field.field_type);
}

function widgetProps(field: FieldDefinition): Record<string, unknown> {
    const widget = widgetFor(field);

    return widget?.inputType ? { inputType: widget.inputType } : {};
}

function setValue(key: string, value: unknown): void {
    values.value = { ...values.value, [key]: value };
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Collapsible
            v-for="(section, index) in sections"
            :key="section.id"
            as-child
            :open="sectionOpen(section)"
            @update:open="setOpen(section.id, $event)"
        >
            <Card :data-field-section="section.id">
                <CardHeader>
                    <CollapsibleCardTitle
                        :title="section.label"
                        :open="sectionOpen(section)"
                        :data-field-section-toggle="section.id"
                    />
                    <CardDescription v-if="section.description !== null">
                        {{ section.description }}
                    </CardDescription>
                </CardHeader>

                <CollapsibleContent
                    class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
                >
                    <CardContent class="flex flex-col gap-6">
                        <FieldWrapper
                            v-for="field in section.fields"
                            :key="field.key"
                            v-slot="{ describedBy }"
                            :field-id="field.key"
                            :label="
                                field.field_type === 'money' &&
                                fieldCurrency(field, values)
                                    ? `${field.label} (${fieldCurrency(field, values)})`
                                    : field.label
                            "
                            :required="field.is_required"
                            :help="field.description"
                            :error="errors?.[field.key]"
                        >
                            <component
                                :is="widgetFor(field)?.component"
                                :field="field"
                                v-bind="widgetProps(field)"
                                :model-value="values[field.key]"
                                :error="errors?.[field.key]"
                                :described-by="describedBy"
                                @update:model-value="
                                    setValue(field.key, $event)
                                "
                            />
                        </FieldWrapper>
                    </CardContent>
                </CollapsibleContent>

                <CardContent
                    v-if="$slots.default && index === sections.length - 1"
                    data-field-section-actions
                >
                    <slot />
                </CardContent>
            </Card>
        </Collapsible>
    </div>
</template>
