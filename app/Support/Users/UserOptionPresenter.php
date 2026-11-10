<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Collection;

class UserOptionPresenter
{
    /**
     * @return array{value: string, label: string, description: string, avatar: array{name: string}}
     */
    public function present(User $user): array
    {
        $label = $this->label($user);

        return [
            'value' => (string) $user->getKey(),
            'label' => $label,
            'description' => $user->email,
            'avatar' => ['name' => $label],
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function presentMany(Collection $users): array
    {
        return array_values(
            $users
                ->map(fn (User $user): array => $this->present($user))
                ->all(),
        );
    }

    public function label(User $user): string
    {
        $name = trim($user->name);

        return $name !== '' ? $name : $user->email;
    }
}
