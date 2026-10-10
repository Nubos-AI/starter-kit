<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Promotion\RollbackPromotionRunAction;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Promotion\PromotionNotRollbackableException;
use App\Models\PromotionRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RollbackPromotion extends Command
{
    protected $signature = 'promotion:rollback {run : ULID des Laufs} {--as-user= : ULID des Handelnden}';

    protected $description = 'Nimmt eine ausgeführte Promotion über ihre Snapshot-Gruppe zurück';

    public function handle(RollbackPromotionRunAction $action): int
    {
        $runArgument = $this->argument('run');
        $run = Str::isUlid($runArgument)
            ? PromotionRun::withoutTenantScope()->find($runArgument)
            : null;

        if (!$run instanceof PromotionRun) {
            $this->error('Der angegebene Promotionslauf wurde nicht gefunden.');

            return self::FAILURE;
        }

        $userOption = $this->option('as-user');

        if (!is_string($userOption) || $userOption === '') {
            $this->error('Die Option --as-user ist erforderlich.');

            return self::FAILURE;
        }

        $actor = Str::isUlid($userOption) ? User::query()->find($userOption) : null;

        if (!$actor instanceof User || (string) $actor->tenant_id !== $run->tenant_id) {
            $this->error('Der angegebene Handelnde ist unbekannt oder gehört nicht zu diesem Mandanten.');

            return self::FAILURE;
        }

        try {
            $rolledBack = $action->execute($actor, $run);
        } catch (AuthorizationException|PromotionNotRollbackableException|MissingActingUserException|TenantUnderMaintenanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $results = $this->resultsOf($rolledBack);

        foreach ($results as $result) {
            $this->line("{$result['action']}  {$result['kind']}:{$result['key']}");
        }

        $count = count($results);

        $this->info("Promotionslauf {$rolledBack->getKey()} wurde zurückgenommen — {$count} Ergebnis(se).");

        return self::SUCCESS;
    }

    /**
     * @return list<array{kind: string, key: string, action: string}>
     */
    private function resultsOf(PromotionRun $run): array
    {
        $rollback = $run->report['rollback'] ?? null;
        $results = is_array($rollback) ? $rollback['results'] ?? null : null;

        if (!is_array($results)) {
            return [];
        }

        $lines = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $lines[] = [
                'kind' => is_string($result['kind'] ?? null) ? $result['kind'] : '',
                'key' => is_string($result['key'] ?? null) ? $result['key'] : '',
                'action' => is_string($result['action'] ?? null) ? $result['action'] : '',
            ];
        }

        return $lines;
    }
}
