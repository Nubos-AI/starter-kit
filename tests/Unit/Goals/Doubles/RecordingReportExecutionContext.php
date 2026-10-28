<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Models\User;
use App\Support\Reports\ReportExecutionContext;
use Closure;
use RuntimeException;

class RecordingReportExecutionContext extends ReportExecutionContext
{
    /**
     * @var list<array{role: string, tenantId: string, userId: string}>
     */
    public array $calls = [];

    /**
     * @var array<string, User>
     */
    private array $usersById = [];

    public function __construct() {}

    public function withUser(User $user): self
    {
        $this->usersById[(string) $user->getKey()] = $user;

        return $this;
    }

    public function runAsViewer(string $tenantId, string $viewerId, Closure $callback): mixed
    {
        return $this->run('viewer', $tenantId, $viewerId, $callback);
    }

    public function runAsDefiner(string $tenantId, string $definerId, Closure $callback): mixed
    {
        return $this->run('definer', $tenantId, $definerId, $callback);
    }

    private function run(string $role, string $tenantId, string $userId, Closure $callback): mixed
    {
        $this->calls[] = ['role' => $role, 'tenantId' => $tenantId, 'userId' => $userId];

        $user = $this->usersById[$userId] ?? null;

        if (!$user instanceof User) {
            throw new RuntimeException("No user [{$userId}] was handed to the execution context.");
        }

        return $callback($user);
    }
}
