import { ref } from 'vue';
import type { Ref } from 'vue';
import UserOptionsController from '@/actions/App/Http/Controllers/Users/UserOptionsController';
import type { SelectOption } from '@/types/ui';

export interface UseUserOptionsReturn {
    options: Ref<SelectOption[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    load: () => Promise<void>;
}

export function useUserOptions(url?: string): UseUserOptionsReturn {
    const options = ref<SelectOption[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const load = async (): Promise<void> => {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(url ?? UserOptionsController.url(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(
                    `Benutzerliste konnte nicht geladen werden (${response.status}).`,
                );
            }

            const payload = (await response.json()) as {
                options?: SelectOption[];
            };

            options.value = Array.isArray(payload.options)
                ? payload.options
                : [];
        } catch (cause) {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Benutzerliste konnte nicht geladen werden.';
            options.value = [];
        } finally {
            loading.value = false;
        }
    };

    return { options, loading, error, load };
}
