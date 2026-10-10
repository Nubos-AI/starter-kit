<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
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
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';

const props = withDefaults(
    defineProps<{
        fields: FieldDefinition[];
        groups?: FieldGroupRow[];
        errors?: Record<string, string>;
        objectTypeId?: string | null;
        hiddenSections?: string[];
    }>(),
    {
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

function widgetFor(field: FieldDefinition) {
    return resolveWidget(field.field_type);
}

function widgetProps(field: FieldDefinition): Record<string, unknown> {
    const widget = widgetFor(field);

    if (!widget?.inputType) {
        return {};
    }

    return widget.step === undefined
        ? { inputType: widget.inputType }
        : { inputType: widget.inputType, step: widget.step };
}

function setValue(key: string, value: unknown): void {
    values.value = { ...values.value, [key]: value };
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Collapsible
            v-for="section in sections"
            :key="section.id"
            as-child
            :open="isOpen(section.id)"
            @update:open="setOpen(section.id, $event)"
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
                                        isOpen(section.id) ? '' : '-rotate-90'
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
                    <CardContent class="flex flex-col gap-6">
                        <FieldWrapper
                            v-for="field in section.fields"
                            :key="field.key"
                            v-slot="{ describedBy }"
                            :field-id="field.key"
                            :label="field.label"
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
