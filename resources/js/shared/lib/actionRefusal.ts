export interface ActionRefusalCatalogue {
  messages: Record<string, string>;
  fallback: string;
}

export function resolveActionRefusal(
  reason: string | null,
  catalogue: ActionRefusalCatalogue,
  t: (key: string) => string,
): string | undefined {
  if (reason === null) {
    return undefined;
  }

  return t(catalogue.messages[reason] ?? catalogue.fallback);
}
