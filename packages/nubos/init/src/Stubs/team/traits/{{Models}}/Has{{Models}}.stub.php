<?php

declare(strict_types=1);

namespace App\Traits\{{Models}};

use App\Models\{{Model}};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait Has{{Models}}
{
    public function {{models}}(): BelongsToMany
    {
        return $this->belongsToMany({{Model}}::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function owned{{Models}}(): HasMany
    {
        return $this->hasMany({{Model}}::class, 'owner_id');
    }

    public function current{{Model}}(): BelongsTo
    {
        return $this->belongsTo({{Model}}::class, 'current_{{model}}_id');
    }

    public function belongsTo{{Model}}({{Model}} ${{model}}): bool
    {
        $currentTenant = app()->bound('current_tenant') ? app('current_tenant') : null;
        if ($currentTenant && method_exists(${{model}}, 'tenant') && ${{model}}->tenant_id !== $currentTenant->id) {
            return false;
        }

        return $this->{{models}}()->where('{{models}}.id', ${{model}}->id)->exists();
    }
}
