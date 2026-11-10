const STORAGE_KEY_PREFIX = 'nubos.records.list.';

export interface RecordListMemory {
    segmentId: string | null;
    search: string;
}

export interface UseRecordListMemoryReturn {
    recall: () => RecordListMemory | null;
    remember: (memory: RecordListMemory) => void;
}

function isNarrowed(memory: RecordListMemory): boolean {
    return memory.segmentId !== null || memory.search !== '';
}

function parse(stored: string): RecordListMemory | null {
    try {
        const parsed = JSON.parse(stored) as Partial<RecordListMemory>;

        const memory: RecordListMemory = {
            segmentId:
                typeof parsed.segmentId === 'string' ? parsed.segmentId : null,
            search: typeof parsed.search === 'string' ? parsed.search : '',
        };

        return isNarrowed(memory) ? memory : null;
    } catch {
        return null;
    }
}

export function useRecordListMemory(
    objectTypeId: string,
): UseRecordListMemoryReturn {
    const key = `${STORAGE_KEY_PREFIX}${objectTypeId}`;

    const recall = (): RecordListMemory | null => {
        try {
            const stored = sessionStorage.getItem(key);

            return stored === null ? null : parse(stored);
        } catch {
            return null;
        }
    };

    const remember = (memory: RecordListMemory): void => {
        try {
            if (!isNarrowed(memory)) {
                sessionStorage.removeItem(key);

                return;
            }

            sessionStorage.setItem(key, JSON.stringify(memory));
        } catch {
            return;
        }
    };

    return { recall, remember };
}
