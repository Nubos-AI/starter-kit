<?php

declare(strict_types=1);

namespace Tests\Fixtures\Records;

use App\Attributes\Engine\BackedByObjectType;
use App\Enums\Authorization\CrudAction;
use App\Traits\Engine\RepresentsObjectType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[BackedByObjectType('devices')]
class Device extends Model
{
    use HasUlids;
    use RepresentsObjectType;

    protected $table = 'devices';

    /** @var list<string> */
    protected $fillable = ['label'];

    /**
     * @return array<string, mixed>
     */
    public static function objectTypeBacking(): array
    {
        return [
            'title' => 'label',
            'index_path' => '/engine/devices',
            'permissions' => [
                CrudAction::View->value => 'organisation.view',
                CrudAction::Update->value => 'organisation.manage',
            ],
        ];
    }
}
