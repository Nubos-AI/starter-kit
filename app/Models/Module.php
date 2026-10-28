<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property bool $disabled
 * @property string|null $version
 * @property Carbon|null $installed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(
    [
        'name',
        'disabled',
        'version',
        'installed_at',
    ]
)]
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disabled' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Module>  $query
     */
    #[Scope]
    protected function registered(Builder $query): void
    {
        $query->whereNotNull('installed_at');
    }
}
