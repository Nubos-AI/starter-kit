import { toast } from 'vue-sonner';

export type RecordToastAction = 'create' | 'edit' | 'delete';

const titleFor: Record<RecordToastAction, (count: number) => string> = {
    create: (count) =>
        count === 1 ? 'Datensatz angelegt' : `${count} Datensätze angelegt`,
    edit: (count) =>
        count === 1
            ? 'Datensatz aktualisiert'
            : `${count} Datensätze aktualisiert`,
    delete: (count) =>
        count === 1 ? 'Datensatz gelöscht' : `${count} Datensätze gelöscht`,
};

export interface RecordToastOptions {
    count?: number;
    description?: string;
}

export function recordToast(
    action: RecordToastAction,
    options: RecordToastOptions = {},
): string | number {
    const description = options.description ?? '';

    return toast.success(titleFor[action](options.count ?? 1), {
        description: description === '' ? undefined : description,
    });
}
