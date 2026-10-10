<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Enums\Approvals\ApprovalExclusion;
use App\Models\CustomRecord;
use App\Support\Governance\FieldEditorResolver;

class ApprovalExclusionResolver
{
    /**
     * @var array<string, array<string, list<ApprovalExclusion>>>
     */
    private array $memo = [];

    public function __construct(
        private readonly FieldEditorResolver $fieldEditorResolver,
    ) {}

    /**
     * @param  list<string>  $gateFieldKeys
     * @return list<string>
     */
    public function excludedUserIds(
        ?CustomRecord $record,
        ApprovalExclusionSet $enabled,
        ?string $triggeredById,
        array $gateFieldKeys,
    ): array {
        return array_keys($this->reasonMap($record, $enabled, $triggeredById, $gateFieldKeys));
    }

    /**
     * @param  list<string>  $gateFieldKeys
     * @return list<ApprovalExclusion>
     */
    public function reasonsFor(
        string $userId,
        ?CustomRecord $record,
        ApprovalExclusionSet $enabled,
        ?string $triggeredById,
        array $gateFieldKeys,
    ): array {
        return $this->reasonMap($record, $enabled, $triggeredById, $gateFieldKeys)[$userId] ?? [];
    }

    /**
     * @param  list<string>  $gateFieldKeys
     */
    public function isDelegateBlocked(
        string $delegateId,
        string $onBehalfOfId,
        ?CustomRecord $record,
        ApprovalExclusionSet $enabled,
        ?string $triggeredById,
        array $gateFieldKeys,
    ): bool {
        $reasons = $this->reasonMap($record, $enabled, $triggeredById, $gateFieldKeys);

        return isset($reasons[$delegateId]) || isset($reasons[$onBehalfOfId]);
    }

    /**
     * @param  list<string>  $gateFieldKeys
     * @return array<string, list<ApprovalExclusion>>
     */
    private function reasonMap(
        ?CustomRecord $record,
        ApprovalExclusionSet $enabled,
        ?string $triggeredById,
        array $gateFieldKeys,
    ): array {
        $fieldKeys = $this->normalizedFieldKeys($gateFieldKeys);

        $key = implode('|', [
            (string) $record?->getKey(),
            (string) $record?->version,
            implode(',', array_map(
                static fn (bool $active): string => $active ? '1' : '0',
                $enabled->toArray(),
            )),
            $triggeredById ?? '',
            implode(',', $fieldKeys),
        ]);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var list<array{ApprovalExclusion, string|null}> $candidates */
        $candidates = [
            [
                ApprovalExclusion::Trigger,
                $enabled->trigger ? $triggeredById : null,
            ],
            [
                ApprovalExclusion::LastEditor,
                $record !== null && $enabled->lastEditor
                    ? $this->fieldEditorResolver->lastEditorOfFields($record, $fieldKeys)
                    : null,
            ],
            [
                ApprovalExclusion::Creator,
                $record !== null && $enabled->creator ? $this->fieldEditorResolver->creatorOf($record) : null,
            ],
            [
                ApprovalExclusion::Owner,
                $enabled->owner ? $record?->owner_id : null,
            ],
        ];

        $reasons = [];

        foreach ($candidates as [$reason, $userId]) {
            if ($userId === null || $userId === '') {
                continue;
            }

            $reasons[$userId][] = $reason;
        }

        return $this->memo[$key] = $reasons;
    }

    /**
     * @param  list<string>  $gateFieldKeys
     * @return list<string>
     */
    private function normalizedFieldKeys(array $gateFieldKeys): array
    {
        $fieldKeys = array_values(array_unique(array_filter(
            $gateFieldKeys,
            static fn (string $fieldKey): bool => $fieldKey !== '',
        )));

        sort($fieldKeys);

        return $fieldKeys;
    }
}
