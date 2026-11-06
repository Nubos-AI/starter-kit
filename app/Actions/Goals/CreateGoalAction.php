<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Goal;
use App\Models\User;
use App\Support\Goals\GoalDefinitionValidator;
use App\Support\Goals\GoalInputRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateGoalAction
{
    public function __construct(private readonly GoalDefinitionValidator $definitionValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, array $input): Goal
    {
        $validated = Validator::make($input, GoalInputRules::rules())->validate();

        Gate::forUser($actor)->authorize('create', Goal::class);

        $this->definitionValidator->validate($actor, $validated);

        $tenantId = TenantContext::currentId((string) $actor->tenant_id);

        return DB::transaction(static fn (): Goal => Goal::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'owner_id' => $actor->getKey(),
        ], GoalInputRules::attributes($validated))));
    }
}
