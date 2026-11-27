<script setup lang="ts">
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Input } from '@/shared/ui/primitives/input';

const props = defineProps<{
  cnameTarget: string;
  canUpdate: boolean;
  domainError?: string;
}>();

const domain = defineModel<string>({ required: true });

const { t } = useI18n();
</script>

<template>
  <FormField for="domain" :label="t('i18n.components.tenancy.own_domain_cards.domain')" :error="props.domainError">
    <Input id="domain" v-model="domain" :disabled="!props.canUpdate" class="sm:max-w-sm" />
    <p class="text-xs text-muted-foreground" data-welcome-cname>
      {{
        t('i18n.components.tenancy.own_domain_cards.cname_hint', {
          value1: props.cnameTarget,
        })
      }}
    </p>
  </FormField>
</template>
