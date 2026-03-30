<?php

declare(strict_types=1);

namespace App\Contracts\Authorization;

use Illuminate\Database\Eloquent\Model;

interface ScopeResolverInterface
{
    /**
     * @return list<Model>
     */
    public function resolve(): array;
}
