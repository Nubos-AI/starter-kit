<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        secret: string | null;
        title?: string;
        description?: string;
        copyLabel?: string;
        copyErrorMessage?: string;
        secretAttrs?: Record<string, string>;
        copyAttrs?: Record<string, string>;
    }>(),
    {
        title: undefined,
        description: undefined,
        copyLabel: undefined,
        copyErrorMessage: undefined,
        secretAttrs: () => ({}),
        copyAttrs: () => ({}),
    },
);

const dismissedSecret = ref<string | null>(null);
const copied = ref<boolean>(false);

const visibleSecret = computed<string | null>(() =>
    props.secret === dismissedSecret.value ? null : props.secret,
);

async function copySecret(): Promise<void> {
    if (visibleSecret.value === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(visibleSecret.value);
        copied.value = true;
    } catch {
        copied.value = false;
        toast.error(
            props.copyErrorMessage ??
                t(
                    'i18n.components.api.api_token_secret_dialog.the_token_could_not_be_copied',
                ),
        );
    }
}

function closeSecret(): void {
    dismissedSecret.value = props.secret;
    copied.value = false;
}
</script>

<template>
    <Dialog :open="visibleSecret !== null" @update:open="closeSecret">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    props.title ??
                    t(
                        'i18n.components.api.api_token_secret_dialog.copy_token_now',
                    )
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        props.description ??
                        t(
                            'i18n.components.api.api_token_secret_dialog.this_secret_will_never_be_shown_again_once_you',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex min-w-0 items-center gap-2">
                <code
                    v-bind="props.secretAttrs"
                    class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-sm"
                    data-api-token-secret
                >
                    {{ visibleSecret }}
                </code>
                <Button
                    v-bind="props.copyAttrs"
                    type="button"
                    variant="outline"
                    size="icon"
                    class="shrink-0"
                    :aria-label="
                        copied
                            ? t(
                                  'i18n.components.api.api_token_secret_dialog.copied',
                              )
                            : (props.copyLabel ??
                              t(
                                  'i18n.components.api.api_token_secret_dialog.copy_token',
                              ))
                    "
                    data-api-token-copy
                    @click="copySecret"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                </Button>
            </div>

            <DialogFooter>
                <Button type="button" @click="closeSecret">{{
                    t('i18n.components.api.api_token_secret_dialog.done')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
