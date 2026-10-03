<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PromotionRunsController from '@/actions/App/Http/Controllers/Promotion/PromotionRunsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        source_refusal?: string | null;
        source_error_key?: string;
    }>(),
    { source_refusal: null, source_error_key: 'source' },
);

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.promotion.create.create_promotion') },
]);

const page = usePage();

const processing = ref<boolean>(false);

const sourceError = computed<string | undefined>(
    () => page.props.errors?.[props.source_error_key],
);

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
    <Head :title="t('i18n.pages.promotion.create.create_promotion')" />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="t('i18n.pages.promotion.create.create_promotion')"
            :description="
                t(
                    'i18n.pages.promotion.create.a_promotion_compares_the_source_configuration_with_live_mode',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.promotion.create.start_comparison')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.promotion.create.the_current_source_configuration_will_be_compared_with_live',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-2">
                <div>
                    <Button
                        v-if="props.source_refusal === null"
                        data-promotion-start
                        :disabled="processing"
                        @click="startComparison"
                    >
                        {{ t('i18n.pages.promotion.create.start_comparison') }}
                    </Button>
                    <TooltipProvider v-else :delay-duration="0">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span tabindex="0" class="inline-flex">
                                    <Button disabled data-promotion-start>
                                        {{
                                            t(
                                                'i18n.pages.promotion.create.start_comparison',
                                            )
                                        }}
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent data-promotion-start-reason>
                                {{ props.source_refusal }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>

                <p
                    v-if="props.source_refusal !== null"
                    class="text-sm text-muted-foreground"
                    data-promotion-source-refusal
                >
                    {{ props.source_refusal }}
                </p>

                <InputError :message="sourceError" />
            </CardContent>
        </Card>
    </div>
</template>
