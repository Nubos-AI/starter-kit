<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import FieldWrapper from '@/components/fields/FieldWrapper.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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

const createSectionState = ref<Record<string, boolean>>({});

function sectionOpen(section: FieldSection, index: number): boolean {
    if (section.fields.some((field) => props.errors[field.key])) {
        return true;
    }

    if (!props.creating) {
        return isOpen(section.id);
    }

    return (
        createSectionState.value[section.id] ??
        (index < 2 || section.fields.some((field) => field.is_required))
    );
}

function toggleSection(id: string, open: boolean): void {
    if (props.creating) {
        createSectionState.value[id] = open;
    } else {
        setOpen(id, open);
    }
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
            v-for="(section, sectionIndex) in sections"
            :key="section.id"
            as-child
            :open="sectionOpen(section, sectionIndex)"
            @update:open="toggleSection(section.id, $event)"
        >
            <Card :data-field-section="section.id">
                <CardHeader>
                    <CardTitle>
                        <CollapsibleTrigger as-child>
                            <Button
                                variant="ghost"
                                :data-field-section-toggle="section.id"
                                class="h-auto w-full justify-between px-0 py-0 text-[length:inherit] leading-none font-semibold hover:bg-transparent has-[>svg]:px-0"
                            >
                                {{ section.label }}
                                <ChevronDown
                                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-out"
                                    :class="
                                        sectionOpen(section, sectionIndex)
                                            ? ''
                                            : '-rotate-90'
                                    "
                                    aria-hidden="true"
                                />
                            </Button>
                        </CollapsibleTrigger>
                    </CardTitle>
                    <CardDescription v-if="section.description !== null">
                        {{ section.description }}
                    </CardDescription>
                </CardHeader>

                <CollapsibleContent
                    class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
                >
                    <CardContent
                        :class="
                            creating
                                ? 'grid grid-cols-1 gap-6 md:grid-cols-2'
                                : 'flex flex-col gap-6'
                        "
                    >
                        <FieldWrapper
                            v-for="field in section.fields"
                            :key="field.key"
                            :class="
                                creating &&
                                ['text_long', 'geo_address', 'file'].includes(
                                    field.field_type,
                                )
                                    ? 'md:col-span-2'
                                    : undefined
                            "
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
            </Card>
        </Collapsible>
    </div>
</template>
