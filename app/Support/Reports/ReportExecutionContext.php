<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Tenancy\ActingUserContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

class ReportExecutionContext
{
    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly RowAccessEnforcement $rowAccess,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(User): TReturn  $callback
     * @return TReturn
     *
     * @throws AuthorizationException
     */
    public function runAsViewer(string $tenantId, string $viewerId, Closure $callback): mixed
    {
        return $this->rowAccess->enforcing(fn (): mixed => $this->execute($tenantId, $viewerId, $callback));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(User): TReturn  $callback
     * @return TReturn
     *
     * @throws AuthorizationException
     */
    public function runAsDefiner(string $tenantId, string $definerId, Closure $callback): mixed
    {
        return $this->rowAccess->withoutEnforcement(fn (): mixed => $this->execute($tenantId, $definerId, $callback));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(User): TReturn  $callback
     * @return TReturn
     *
     * @throws AuthorizationException
     */
    private function execute(string $tenantId, string $userId, Closure $callback): mixed
    {
        $result = null;

        $this->actingUserContext->run($tenantId, $userId, function (User $actingUser) use ($callback, &$result): void {
            $result = $callback($actingUser);
        });

        /** @var TReturn $result */
        return $result;
    }
}
