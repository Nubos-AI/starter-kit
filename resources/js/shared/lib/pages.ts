import type { ModuleComponentLoader } from '@/shared/types/modules';

function pageName(path: string): string | null {
  const segments = path.split('/');
  const pagesAt = segments.indexOf('pages');

  if (pagesAt === -1 || !path.endsWith('.vue')) {
    return null;
  }

  return segments
    .slice(pagesAt + 1)
    .join('/')
    .slice(0, -'.vue'.length);
}

export function pageRegistry(files: Record<string, ModuleComponentLoader>): Record<string, ModuleComponentLoader> {
  return Object.entries(files).reduce<Record<string, ModuleComponentLoader>>((pages, [path, load]) => {
    const name = pageName(path);

    if (name === null) {
      return pages;
    }

    if (name in pages) {
      throw new Error(`Duplicate page [${name}].`);
    }

    pages[name] = load;

    return pages;
  }, {});
}

const reportedOverrides = new Set<string>();

export function pageLoader(
  name: string,
  applicationPages: Record<string, ModuleComponentLoader>,
  modulePages: Record<string, ModuleComponentLoader>,
  notify: (message: string) => void = () => {},
): ModuleComponentLoader | undefined {
  const applicationPage = applicationPages[name];

  if (applicationPage !== undefined && modulePages[name] !== undefined && !reportedOverrides.has(name)) {
    reportedOverrides.add(name);
    notify(`The application page [${name}] overrides the module page of the same name.`);
  }

  return applicationPage ?? modulePages[name];
}
