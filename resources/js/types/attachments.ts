export interface RecordFile {
    id: string;
    fileName: string;
    mimeType: string;
    size: number;
    fieldKey: string | null;
    createdAt: string | null;
    uploadedBy: { id: string; label: string } | null;
    canDelete: boolean;
}

export interface RecordFilesResponse {
    data: RecordFile[];
    meta: { current_page: number; last_page: number; total: number };
    permissions: { canUpload: boolean };
    upload: { maxSizeKb: number; allowedMimes: string[] };
}
