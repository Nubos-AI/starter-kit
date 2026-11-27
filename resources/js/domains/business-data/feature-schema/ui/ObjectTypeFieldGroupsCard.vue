<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import FieldGroupsController from '@/actions/App/Http/Controllers/Engine/FieldGroupsController';
import type { FieldGroupRow } from '@/domains/business-data/ui-record-field';
import { useI18n } from '@/shared/composables/useI18n';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';

const { t } = useI18n();

const props = defineProps<{
  objectTypeSlug: string;
  groups: FieldGroupRow[];
  editable: boolean;
}>();

const visitOptions = { preserveScroll: true, preserveState: false };

const newKey = ref<string>('');
const newLabel = ref<string>('');
const newDescription = ref<string>('');

const {
  pendingDelete: pendingRemoval,
  deletePending: removalPending,
  deleteDialogOpen: removalDialogOpen,
  requestDelete: requestRemoval,
  confirmDelete: confirmRemoval,
} = useRowDeletion<FieldGroupRow>({
  url: (group) =>
    FieldGroupsController.destroy.url({
      objectType: props.objectTypeSlug,
      fieldGroup: group.id,
    }),
});

const canCreate = computed<boolean>(() => newKey.value.trim() !== '' && newLabel.value.trim() !== '');

const sortedGroups = computed<FieldGroupRow[]>(() =>
  [...props.groups].sort((left, right) => left.position - right.position),
);

const removalDescription = computed<string>(() =>
  pendingRemoval.value === null
    ? ''
    : t(
        'i18n.components.engine.object_type.object_type_field_groups_card.the_fields_in_the_group_will_be_retained_without',
        { value1: pendingRemoval.value.label },
      ),
);

function createGroup(): void {
  if (!canCreate.value) {
    return;
  }

  router.post(
    FieldGroupsController.store.url({ objectType: props.objectTypeSlug }),
    {
      key: newKey.value.trim(),
      label: newLabel.value.trim(),
      description: newDescription.value.trim(),
    },
    visitOptions,
  );

  newKey.value = '';
  newLabel.value = '';
  newDescription.value = '';
}

function saveGroup(group: FieldGroupRow, payload: Record<string, string>): void {
  router.put(
    FieldGroupsController.update.url({
      objectType: props.objectTypeSlug,
      fieldGroup: group.id,
    }),
    payload,
    visitOptions,
  );
}

function renameGroup(group: FieldGroupRow, label: string): void {
  const next = label.trim();

  if (next === '' || next === group.label) {
    return;
  }

  saveGroup(group, { label: next });
}

function describeGroup(group: FieldGroupRow, description: string): void {
  const next = description.trim();

  if (next === (group.description ?? '')) {
    return;
  }

  saveGroup(group, { description: next });
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('i18n.components.engine.object_type.object_type_field_groups_card.field_groups') }}</CardTitle>
      <CardDescription>
        {{
          t(
            'i18n.components.engine.object_type.object_type_field_groups_card.groups_bring_related_fields_together_such_as_address_fields',
          )
        }}
      </CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <EmptyState
        v-if="props.groups.length === 0"
        variant="inline"
        :description="
          t(
            'i18n.components.engine.object_type.object_type_field_groups_card.no_field_groups_yet_without_groups_all_fields_appear',
          )
        "
      />

      <div v-else class="flex flex-col gap-2">
        <div
          v-for="group in sortedGroups"
          :key="group.id"
          class="flex flex-col gap-2 rounded-md border px-3 py-2"
          :data-field-group="group.key"
        >
          <div class="flex flex-wrap items-center gap-2">
            <Input
              :model-value="group.label"
              :readonly="!props.editable"
              class="w-56"
              :aria-label="
                t('i18n.components.engine.object_type.object_type_field_groups_card.name_of_group', {
                  value1: group.label,
                })
              "
              @change="renameGroup(group, ($event.target as HTMLInputElement).value)"
            />
            <code class="text-xs text-muted-foreground">
              {{ group.key }}
            </code>

            <Button
              v-if="props.editable"
              type="button"
              variant="ghost"
              size="icon"
              class="ml-auto"
              :aria-label="
                t('i18n.components.engine.object_type.object_type_field_groups_card.delete_group', {
                  value1: group.label,
                })
              "
              :data-field-group-remove="group.key"
              @click="requestRemoval(group)"
            >
              <Trash2 class="size-4" />
            </Button>
          </div>

          <Input
            :model-value="group.description ?? ''"
            :readonly="!props.editable"
            :placeholder="
              t('i18n.components.engine.object_type.object_type_field_groups_card.what_belongs_in_this_group')
            "
            :aria-label="
              t('i18n.components.engine.object_type.object_type_field_groups_card.description_of_group', {
                value1: group.label,
              })
            "
            :data-field-group-description="group.key"
            @change="describeGroup(group, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>

      <div v-if="props.editable" class="flex flex-col gap-2 rounded-md border p-3">
        <div class="flex flex-wrap items-end gap-2">
          <FormField
            for="field-group-key"
            :label="t('i18n.components.engine.object_type.object_type_field_groups_card.key')"
          >
            <Input
              id="field-group-key"
              v-model="newKey"
              :placeholder="t('i18n.components.engine.object_type.object_type_field_groups_card.address')"
            />
          </FormField>
          <FormField
            for="field-group-label"
            :label="t('i18n.components.engine.object_type.object_type_field_groups_card.label')"
          >
            <Input
              id="field-group-label"
              v-model="newLabel"
              :placeholder="t('i18n.components.engine.object_type.object_type_field_groups_card.address_2')"
            />
          </FormField>
          <Button type="button" data-field-group-create :disabled="!canCreate" @click="createGroup">
            {{ t('i18n.components.engine.object_type.object_type_field_groups_card.add_group') }}
          </Button>
        </div>
        <FormField
          for="field-group-description"
          :label="t('i18n.components.engine.object_type.object_type_field_groups_card.description')"
        >
          <Input
            id="field-group-description"
            v-model="newDescription"
            :placeholder="
              t('i18n.components.engine.object_type.object_type_field_groups_card.what_belongs_in_this_group')
            "
          />
        </FormField>
      </div>
    </CardContent>
  </Card>

  <ConfirmDialog
    v-model:open="removalDialogOpen"
    :title="t('i18n.components.engine.object_type.object_type_field_groups_card.delete_field_group')"
    :description="removalDescription"
    :confirm-label="t('i18n.components.engine.object_type.object_type_field_groups_card.delete')"
    variant="destructive"
    :pending="removalPending"
    @confirm="confirmRemoval"
  />
</template>
