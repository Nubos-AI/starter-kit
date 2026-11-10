<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';

const props = defineProps<{
    cnameTarget: string;
    canUpdate: boolean;
    domainError?: string;
}>();

const domain = defineModel<string>({ required: true });

const { t } = useI18n();
</script>

<template>
    <div class="grid gap-2">
        <Label for="domain">{{
            t('i18n.components.tenancy.own_domain_cards.domain')
        }}</Label>
        <Input
            id="domain"
            v-model="domain"
            :disabled="!props.canUpdate"
            class="sm:max-w-sm"
        />
        <InputError :message="props.domainError" />
        <p class="text-xs text-muted-foreground" data-welcome-cname>
            {{
                t('i18n.components.tenancy.own_domain_cards.cname_hint', {
                    value1: props.cnameTarget,
                })
            }}
        </p>
    </div>
</template>
