export interface ListGridRow {
  id: string;
  can_update: boolean;
  update_reason?: string | null;
  can_delete: boolean;
  delete_reason?: string | null;
}

export interface ListGridCopy<Row> {
  deleteSelected?: string;
  emptyTitle: string;
  emptyDescription: string;
  create: string;
  deleteTitle: string;
  deleteDescription: (row: Row | null) => string;
}

export interface ListGridRowOpen<Row> {
  label: string;
  isAllowed: (row: Row) => boolean;
  reason: (row: Row) => string | undefined;
  testId?: string;
  href?: (row: Row) => string;
}

export interface ListGridRowDelete<Row> {
  label?: string;
  reason?: (row: Row) => string | undefined;
  testId?: string;
}
