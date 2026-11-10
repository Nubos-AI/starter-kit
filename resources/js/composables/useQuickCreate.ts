import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import CreatableObjectTypesController from '@/actions/App/Http/Controllers/Engine/CreatableObjectTypesController';
import { store } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorResponse, toFieldErrors } from '@/lib/errorResponse';
import { extractRecord } from '@/lib/recordPayload';
import { recordToast } from '@/lib/recordToast';
import { show } from '@/routes/engine/records';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const REQUEST_TIMEOUT_MS = 15000;

const SAVE_ERROR_MESSAGE = 'Der Datensatz konnte nicht gespeichert werden.';

export interface CreatableObjectType extends RecordObjectType {
    fieldDefinitions: FieldDefinition[];
}

export interface UseQuickCreateReturn {
    catalog: Ref<CreatableObjectType[]>;
    selectedType: Ref<CreatableObjectType | null>;
    values: Ref<Record<string, unknown>>;
    errors: Ref<Record<string, string>>;
    saving: Ref<boolean>;
    loadCatalog: () => Promise<void>;
    select: (type: CreatableObjectType) => void;
    submit: () => Promise<void>;
    reset: () => void;
}

export function useQuickCreate(): UseQuickCreateReturn {
    const catalog = ref<CreatableObjectType[]>([]);
    const selectedType = ref<CreatableObjectType | null>(null);
    const values = ref<Record<string, unknown>>({});
    const errors = ref<Record<string, string>>({});
    const saving = ref<boolean>(false);

    const loadCatalog = async (): Promise<void> => {
        try {
            const response = await fetch(CreatableObjectTypesController.url(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: buildHeaders(),
            });

            if (!response.ok) {
                throw new Error(
                    `Creatable object types responded with status ${response.status}`,
                );
            }

            catalog.value = (await response.json()) as CreatableObjectType[];
        } catch {
            catalog.value = [];
        }
    };

    const select = (type: CreatableObjectType): void => {
        selectedType.value = type;
        values.value = {};
        errors.value = {};
    };

    const reset = (): void => {
        selectedType.value = null;
        values.value = {};
        errors.value = {};
        saving.value = false;
    };

    const submit = async (): Promise<void> => {
        const type = selectedType.value;

        if (type === null || saving.value) {
            return;
        }

        saving.value = true;
        errors.value = {};

        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            const response = await fetch(store.url({ objectType: type }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify({ data: values.value }),
                signal: controller.signal,
            });

            if (response.status === 200 || response.status === 201) {
                const record = extractRecord(await response.json());
                recordToast('create');
                router.visit(show.url({ record: record.id }));

                return;
            }

            const failure = await readErrorResponse(response);

            if (response.status === 422) {
                errors.value = toFieldErrors(failure.errors);
            }

            toast.error(failure.message ?? SAVE_ERROR_MESSAGE);
        } catch {
            toast.error(SAVE_ERROR_MESSAGE);
        } finally {
            clearTimeout(timeout);
            saving.value = false;
        }
    };

    return {
        catalog,
        selectedType,
        values,
        errors,
        saving,
        loadCatalog,
        select,
        submit,
        reset,
    };
}
