<?php

declare(strict_types=1);

namespace App\Actions\Routing;

use App\Actions\Engine\UpdateRecordAction;
use App\DTOs\Routing\AssignmentOutcome;
use App\DTOs\Routing\AssignmentRequest;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Routing\AssignmentSelector;
use Throwable;

class AssignRecordOwnerAction
{
    public function __construct(
        private readonly AssignmentSelector $assignmentSelector,
        private readonly UpdateRecordAction $updateRecordAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record, AssignmentRequest $request): AssignmentOutcome
    {
        $outcome = $this->assignmentSelector->select($record, $request);
        $candidate = $outcome->user;

        if (!$candidate instanceof User) {
            return $outcome;
        }

        $this->updateRecordAction->execute($record, [
            'version' => $record->version,
            'owner_id' => (string) $candidate->getKey(),
        ]);

        return $outcome;
    }
}
