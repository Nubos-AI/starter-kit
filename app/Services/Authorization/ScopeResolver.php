<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Contracts\Authorization\ScopeResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ScopeResolver implements ScopeResolverInterface
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * @return list<Model>
     */
    public function resolve(): array
    {
        return array_values(array_filter([
            app()->bound('current_tenant') ? app('current_tenant') : null,
            $this->request->attributes->get('current_workspace'),
            $this->request->attributes->get('current_team'),
        ]));
    }
}
