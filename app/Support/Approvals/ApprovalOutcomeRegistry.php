<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Contracts\Approvals\ApprovalOutcomeHandler;
use App\Exceptions\Approvals\UnsupportedApprovalAnchorException;
use InvalidArgumentException;

class ApprovalOutcomeRegistry
{
    /**
     * @var array<string, ApprovalOutcomeHandler>
     */
    private array $handlers = [];

    /**
     * @param  iterable<ApprovalOutcomeHandler>  $handlers
     *
     * @throws InvalidArgumentException
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $anchorType = $handler->anchorType();

            if (isset($this->handlers[$anchorType])) {
                throw new InvalidArgumentException("An approval outcome handler for anchor type [{$anchorType}] is registered more than once.");
            }

            $this->handlers[$anchorType] = $handler;
        }
    }

    /**
     * @throws UnsupportedApprovalAnchorException
     */
    public function for(string $anchorType): ApprovalOutcomeHandler
    {
        return $this->handlers[$anchorType] ?? throw UnsupportedApprovalAnchorException::forAnchorType($anchorType);
    }

    /**
     * @return list<string>
     */
    public function anchorTypes(): array
    {
        return array_keys($this->handlers);
    }
}
