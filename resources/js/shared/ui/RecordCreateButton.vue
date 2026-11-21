<script setup lang="ts" generic="ObjectType extends { id: string; label: string }">
import { ChevronDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { Button } from '@/shared/ui/primitives/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/shared/ui/primitives/dropdown-menu';

const { t } = useI18n();

const props = defineProps<{
  objectType?: ObjectType;
  creatableTypes?: ObjectType[];
  label?: string;
}>();

const emit = defineEmits<{
  create: [objectType?: ObjectType];
}>();

const types = computed<ObjectType[]>(() => props.creatableTypes ?? (props.objectType ? [props.objectType] : []));

const isMulti = computed<boolean>(() => types.value.length > 1);

const isScoped = computed<boolean>(() => types.value.length > 0);
</script>

<template>
  <div>
    <CreateButton
      v-if="!isScoped"
      :label="props.label ?? t('i18n.components.engine.create_button.create')"
      @click="emit('create')"
    />

    <CreateButton
      v-else-if="!isMulti"
      :label="
        props.label ??
        t('i18n.components.engine.create_button.create_3', {
          value1: objectType!.label,
        })
      "
      @click="emit('create', types[0])"
    />

    <DropdownMenu v-else>
      <DropdownMenuTrigger as-child>
        <Button variant="create" data-create-button>
          <Plus class="size-4" />
          {{ props.label ?? t('i18n.components.engine.create_button.create') }}
          <ChevronDown class="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent extension-point="menus.create-button" :extension-context="$props" align="end" class="w-56">
        <DropdownMenuGroup>
          <DropdownMenuItem v-for="type in types" :key="type.id" @select="emit('create', type)">
            {{ type.label }}
            {{ t('i18n.components.engine.create_button.create_2') }}
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
