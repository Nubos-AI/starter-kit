export interface ActivityOption {
    id: string;
    name: string;
}

export interface RecordActivityItem {
    id: string;
    subject: string;
    occurredAt: string;
    result: string | null;
    activityTypeId: string | null;
    assigneeId: string | null;
    typeName: string | null;
    assigneeName: string | null;
}

export interface ActivityListResponse {
    data: RecordActivityItem[];
    activityTypes: ActivityOption[];
    assignees: ActivityOption[];
    canManage: boolean;
    meta: { current_page: number; last_page: number };
}
