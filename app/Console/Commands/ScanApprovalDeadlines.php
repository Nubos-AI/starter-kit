<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Approvals\ApprovalDeadlineRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanApprovalDeadlines extends Command
{
    protected $signature = 'approvals:scan-deadlines';

    protected $description = 'Escalate approval stages whose deadline has expired across all tenants';

    public function __construct(private readonly ApprovalDeadlineRunner $runner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->runner->run(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
