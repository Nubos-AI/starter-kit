<?php

declare(strict_types=1);

namespace App\Enums\Sharing;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

enum ShareGranteeType: string
{
    case User = 'user';

    case Team = 'team';

    case Role = 'role';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::User => User::class,
            self::Team => Team::class,
            self::Role => Role::class,
        };
    }

    public static function fromModelClass(string $modelClass): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->modelClass() === $modelClass) {
                return $case;
            }
        }

        return null;
    }
}
