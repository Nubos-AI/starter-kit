<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PromotionRunsController from '@/actions/App/Http/Controllers/Promotion/PromotionRunsController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import ReasonedButton from '@/shared/ui/ReasonedButton.vue';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    source_refusal?: string | null;
    source_error_key?: string;
  }>(),
  { source_refusal: null, source_error_key: 'source' },
);

usePageBreadcrumbs(() => [{ title: t('i18n.pages.promotion.create.create_promotion') }]);

const page = usePage();

const processing = ref<boolean>(false);

const sourceError = computed<string | undefined>(() => page.props.errors?.[props.source_error_key]);

function startComparison(): void {
  processing.value = true;

  router.post(
    PromotionRunsController.store.url(),
    {},
    {
      onFinish: () => {
        processing.value = false;
      },
    },
  );
}
</script>

<template>
  <PageShell
    :title="t('i18n.pages.promotion.create.create_promotion')"
    :description="t('i18n.pages.promotion.create.a_promotion_compares_the_source_configuration_with_live_mode')"
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.promotion.create.start_comparison') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.promotion.create.the_current_source_configuration_will_be_compared_with_live') }}
        </CardDescription>
      </CardHeader>
      <CardContent class="grid gap-2">
        <div>
          <ReasonedButton
            :reason="props.source_refusal"
            :disabled="processing"
            data-promotion-start
            @click="startComparison"
          >
            {{ t('i18n.pages.promotion.create.start_comparison') }}
          </ReasonedButton>
        </div>

        <p v-if="props.source_refusal !== null" class="text-sm text-muted-foreground" data-promotion-source-refusal>
          {{ props.source_refusal }}
        </p>

        <InputError :message="sourceError" />
      </CardContent>
    </Card>
  </PageShell>
</template>
