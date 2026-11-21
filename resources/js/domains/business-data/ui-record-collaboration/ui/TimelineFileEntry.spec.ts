import { fireEvent, render, screen } from '@testing-library/vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { destroy } from '@/actions/App/Http/Controllers/Engine/RecordFilesController';
import type { TimelineEntry } from '@/domains/business-data/data/types/timeline';
import { serveEmpty, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import TimelineFileEntry from './TimelineFileEntry.vue';

const entry: TimelineEntry = {
  id: 'timeline-1',
  sourceKey: 'file',
  sourceId: 'file-1',
  actorId: null,
  actorType: null,
  actorLabel: 'Anna',
  channel: 'web',
  apiTokenLabel: null,
  occurredAt: '2026-09-19T12:00:00Z',
  payload: { fileName: 'Vertrag.pdf' },
  file: {
    id: 'file-1',
    fileName: 'Vertrag.pdf',
    mimeType: 'application/pdf',
    size: 2048,
    createdAt: null,
    uploadedBy: null,
    fieldKey: null,
    canDelete: true,
  },
};
const confirmStub = {
  props: ['open', 'pending'],
  emits: ['confirm', 'update:open'],
  template:
    '<div v-if="open" data-confirm><button :disabled="pending" @click="$emit(\'confirm\')">Löschen bestätigen</button><slot /></div>',
};

function row(value = entry) {
  return render(TimelineFileEntry, {
    props: { entry: value, recordId: 'record-1' },
    global: { stubs: { ConfirmDialog: confirmStub } },
  });
}

function fileRoute() {
  return destroy({ record: 'record-1', attachment: 'file-1' });
}

async function confirmDeletion(): Promise<void> {
  await fireEvent.click(screen.getByTitle('Löschen'));
  await fireEvent.click(await screen.findByText('Löschen bestätigen'));
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'test-team' });
});

describe('TimelineFileEntry', () => {
  it('offers preview and download directly on the timeline entry', () => {
    row();

    expect(screen.getAllByText('Vertrag.pdf').length).toBeGreaterThan(0);
    expect(screen.getByTitle('Herunterladen').getAttribute('href')).toBe('/test-team/records/record-1/files/file-1');
    expect(screen.getByTitle('Vorschau').getAttribute('href')).toContain('preview=1');
  });

  it('requires confirmation then removes actions and refreshes the timeline', async () => {
    const served = serveEmpty(fileRoute());
    const { emitted } = row();

    await fireEvent.click(screen.getByTitle('Löschen'));
    expect(served.requests).toHaveLength(0);

    await fireEvent.click(await screen.findByText('Löschen bestätigen'));

    expect(await screen.findByText(/nicht mehr verfügbar/)).toBeTruthy();
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe('/test-team/records/record-1/files/file-1');
    expect(emitted('changed')).toHaveLength(1);
    expect(screen.queryByRole('link')).toBeNull();
  });

  it('blocks the confirmation while the deletion is still running', async () => {
    const served = serveEmpty(fileRoute(), { held: true });
    const { emitted } = row();

    await confirmDeletion();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    const confirm = screen.getByText('Löschen bestätigen');
    expect(confirm.hasAttribute('disabled')).toBe(true);
    expect(emitted('changed')).toBeUndefined();

    served.release();

    expect(await screen.findByText(/nicht mehr verfügbar/)).toBeTruthy();
    expect(served.requests).toHaveLength(1);
  });

  it('keeps failed deletions retryable and leaves the file available', async () => {
    serveServerError(fileRoute(), { message: 'storage offline' });
    const { emitted } = row();

    await confirmDeletion();

    expect((await screen.findByRole('alert')).textContent).toContain('nicht gelöscht');
    expect(screen.getByTitle('Herunterladen')).toBeTruthy();
    expect(emitted('changed')).toBeUndefined();
  });

  it('shows history without dead download links for deleted files', () => {
    row({ ...entry, file: null });

    expect(screen.getByText('Vertrag.pdf')).toBeTruthy();
    expect(screen.getByText(/nicht mehr verfügbar/)).toBeTruthy();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('hides deletion when field or record write access is missing', () => {
    row({
      ...entry,
      file: { ...entry.file!, canDelete: false },
    });

    expect(screen.queryByTitle('Löschen')).toBeNull();
    expect(screen.getByTitle('Herunterladen')).toBeTruthy();
  });
});
