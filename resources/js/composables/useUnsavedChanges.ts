import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import type { ComputedRef, MaybeRefOrGetter, Ref } from 'vue';
import {
    computed,
    getCurrentInstance,
    onMounted,
    onScopeDispose,
    ref,
    toValue,
} from 'vue';

interface UnsavedChangesOptions {
    values: () => unknown;
    backHref: MaybeRefOrGetter<string>;
}

interface UnsavedChanges {
    isDirty: ComputedRef<boolean>;
    promptOpen: Ref<boolean>;
    requestLeave: () => void;
    confirmLeave: () => void;
    cancelLeave: () => void;
    markSaved: () => void;
}

function serialize(values: unknown): string {
    return JSON.stringify(values) ?? '';
}

export function useUnsavedChanges(
    options: UnsavedChangesOptions,
): UnsavedChanges {
    const baseline = ref<string>(serialize(options.values()));
    const promptOpen = ref<boolean>(false);
    const pendingHref = ref<string | null>(null);

    let leaving = false;

    const isDirty = computed<boolean>(
        () => serialize(options.values()) !== baseline.value,
    );

    if (getCurrentInstance() !== null) {
        onMounted(() => {
            baseline.value = serialize(options.values());
        });
    }

    function isNavigation(visit: PendingVisit): boolean {
        return (
            visit.method === 'get' &&
            visit.prefetch !== true &&
            (visit.only ?? []).length === 0 &&
            (visit.except ?? []).length === 0
        );
    }

    const stopGuard = router.on('before', (event) => {
        if (leaving || !isDirty.value || !isNavigation(event.detail.visit)) {
            return;
        }

        pendingHref.value = String(event.detail.visit.url);
        promptOpen.value = true;

        return false;
    });

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (!isDirty.value) {
            return;
        }

        event.preventDefault();
    }

    const hasWindow = typeof window !== 'undefined';

    if (hasWindow) {
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    onScopeDispose(() => {
        stopGuard();

        if (hasWindow) {
            window.removeEventListener('beforeunload', onBeforeUnload);
        }
    });

    function requestLeave(): void {
        const href = toValue(options.backHref);

        if (!isDirty.value) {
            router.visit(href);

            return;
        }

        pendingHref.value = href;
        promptOpen.value = true;
    }

    function confirmLeave(): void {
        const href = pendingHref.value ?? toValue(options.backHref);

        promptOpen.value = false;
        pendingHref.value = null;
        leaving = true;

        router.visit(href, {
            onFinish: () => {
                leaving = false;
            },
        });
    }

    function cancelLeave(): void {
        promptOpen.value = false;
        pendingHref.value = null;
    }

    function markSaved(): void {
        baseline.value = serialize(options.values());
    }

    return {
        isDirty,
        promptOpen,
        requestLeave,
        confirmLeave,
        cancelLeave,
        markSaved,
    };
}
