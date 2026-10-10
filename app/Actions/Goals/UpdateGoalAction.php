<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Goal;
use App\Models\User;
use App\Support\Goals\GoalDefinitionValidator;
use App\Support\Goals\GoalInputRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateGoalAction
{
    public function __construct(private readonly GoalDefinitionValidator $definitionValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Goal $goal, array $input): Goal
    {
        Gate::forUser($actor)->authorize('update', $goal);

        $validated = Validator::make($input, GoalInputRules::rules())->validate();

        $this->definitionValidator->validate($actor, $validated);

        return DB::transaction(static function () use ($goal, $validated): Goal {
            $goal->fill(GoalInputRules::attributes($validated));

            $goal->save();

            return $goal;
        });
    }
}
