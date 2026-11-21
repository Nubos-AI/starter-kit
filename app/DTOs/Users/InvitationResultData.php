<?php

declare(strict_types=1);

namespace App\DTOs\Users;

readonly class InvitationResultData
{
    /**
     * @param  list<string>  $invited
     * @param  list<string>  $renewed
     * @param  list<string>  $skipped
     */
    public function __construct(
        public array $invited,
        public array $renewed,
        public array $skipped,
    ) {}
}
