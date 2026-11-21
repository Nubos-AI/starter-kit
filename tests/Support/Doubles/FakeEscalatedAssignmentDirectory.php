<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\RoleAssignment;
use App\Support\Authorization\EscalatedAssignmentDirectory;
use Illuminate\Support\Collection;

class FakeEscalatedAssignmentDirectory extends EscalatedAssignmentDirectory
{
    public int $lockedReads = 0;

    /**
     * @param  list<RoleAssignment>  $assignments
     */
    public function __construct(private array $assignments = []) {}

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function lockedAssignments(): Collection
    {
        $this->lockedReads++;

        return new Collection($this->assignments);
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function assignments(): Collection
    {
        return new Collection($this->assignments);
    }
}
