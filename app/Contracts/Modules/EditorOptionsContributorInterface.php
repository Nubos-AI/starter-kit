<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

interface EditorOptionsContributorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function options(): array;
}
