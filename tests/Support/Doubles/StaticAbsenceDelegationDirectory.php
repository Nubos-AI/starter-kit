<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Governance\AbsenceDelegationDirectory;
use Carbon\CarbonImmutable;

class StaticAbsenceDelegationDirectory extends AbsenceDelegationDirectory
{
    /**
     * @var list<array{method: string, subject: list<string>, moment: CarbonImmutable}>
     */
    public array $lookups = [];

    /**
     * @param  list<array{user_id: string, delegate_id: string}>  $rows
     */
    private function __construct(private readonly array $rows) {}

    /**
     * @param  list<array{user_id: string, delegate_id: string}>  $rows
     */
    public static function covering(array $rows): self
    {
        $directory = new self($rows);

        app()->instance(AbsenceDelegationDirectory::class, $directory);

        return $directory;
    }

    public function delegateIdCovering(string $userId, CarbonImmutable $moment): ?string
    {
        $this->lookups[] = ['method' => 'delegateIdCovering', 'subject' => [$userId], 'moment' => $moment];

        foreach ($this->rows as $row) {
            if ($row['user_id'] === $userId) {
                return $row['delegate_id'];
            }
        }

        return null;
    }

    public function delegatorIdsCovering(string $delegateId, CarbonImmutable $moment): array
    {
        $this->lookups[] = ['method' => 'delegatorIdsCovering', 'subject' => [$delegateId], 'moment' => $moment];

        $userIds = [];

        foreach ($this->rows as $row) {
            if ($row['delegate_id'] === $delegateId) {
                $userIds[] = $row['user_id'];
            }
        }

        return $userIds;
    }

    public function coveredUserIds(array $userIds, CarbonImmutable $moment): array
    {
        $this->lookups[] = ['method' => 'coveredUserIds', 'subject' => $userIds, 'moment' => $moment];

        $covered = [];

        foreach ($this->rows as $row) {
            if (in_array($row['user_id'], $userIds, true)) {
                $covered[] = $row['user_id'];
            }
        }

        return $covered;
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function moments(): array
    {
        return array_values(array_map(
            static fn (array $lookup): CarbonImmutable => $lookup['moment'],
            $this->lookups,
        ));
    }
}
