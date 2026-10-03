<?php

declare(strict_types=1);

namespace App\DTOs\Routing;

class ActionContext
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $nodeRecordIds
     * @param  array<string, mixed>  $trigger
     */
    public function __construct(
        public readonly string $automationId,
        public readonly string $versionId,
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly ?string $recordId,
        public readonly string $objectTypeId,
        public readonly ?string $actorId,
        public readonly array $config,
        public readonly string $tenantId,
        public readonly array $nodeRecordIds = [],
        public readonly array $trigger = [],
        public readonly ?string $rootRunId = null,
    ) {}
}
