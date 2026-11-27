import type { LabelledRef, SelectOption } from '@/shared/types/ui';

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isPlainRecord(value: unknown): value is Record<string, unknown> {
  return isRecord(value) && !Array.isArray(value);
}

export function isSelectOption(value: unknown): value is SelectOption {
  return isRecord(value) && typeof value.value === 'string' && typeof value.label === 'string';
}

export function optionLabelOf(options: SelectOption[], value: string): string {
  return options.find((option) => option.value === value)?.label ?? value;
}

export function recordOf(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {};
}

export function asString(raw: unknown): string {
  return typeof raw === 'string' ? raw : '';
}

export function asNullableString(raw: unknown): string | null {
  return typeof raw === 'string' ? raw : null;
}

export function asNullableText(raw: unknown): string | null {
  return raw === undefined || raw === null ? null : String(raw);
}

export function labelledRefOf(raw: unknown): LabelledRef | null {
  if (!isRecord(raw)) {
    return null;
  }

  return { id: String(raw.id ?? ''), label: String(raw.label ?? '') };
}

export function asNumber(raw: unknown): number {
  return typeof raw === 'number' && Number.isFinite(raw) ? raw : 0;
}

export function listOf(raw: unknown): unknown[] {
  return Array.isArray(raw) ? raw : [];
}

export function asStringList(raw: unknown): string[] {
  return listOf(raw).filter((entry): entry is string => typeof entry === 'string');
}

export function optionsOf(raw: unknown): SelectOption[] {
  return listOf(raw).map((entry) => ({
    value: asString(recordOf(entry).value),
    label: asString(recordOf(entry).label),
  }));
}

export function dataOf(body: unknown): Record<string, unknown> {
  return recordOf(recordOf(body).data);
}
