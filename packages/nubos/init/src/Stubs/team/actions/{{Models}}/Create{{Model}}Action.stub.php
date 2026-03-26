<?php

declare(strict_types=1);

namespace App\Actions\{{Models}};

use App\Events\{{Models}}\{{Model}}Created;
use App\Models\{{Model}};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class Create{{Model}}Action
{
    /**
     * @throws Throwable
     */
    public function execute(User $owner, array $data): {{Model}}
    {
        return DB::transaction(function () use ($owner, $data): {{Model}} {
            ${{model}} = {{Model}}::query()->create([
                'name' => $data['name'],
                'owner_id' => $owner->id,
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'personal_{{model}}' => $data['personal_{{model}}'] ?? false,
            ]);

            ${{model}}->users()->attach($owner->id, ['role' => 'owner']);

            $owner->update(['current_{{model}}_id' => ${{model}}->id]);

            event(new {{Model}}Created(${{model}}));

            return ${{model}};
        });
    }
}
