export function dashboardPayload(name: string, description: string): Record<string, string | null> {
  return {
    name,
    description: description.trim() === '' ? null : description,
  };
}
