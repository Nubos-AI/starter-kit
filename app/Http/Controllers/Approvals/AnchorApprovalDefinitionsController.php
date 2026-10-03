<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Actions\Approvals\DeleteAnchorApprovalDefinitionAction;
use App\Actions\Approvals\SaveAnchorApprovalDefinitionAction;
use App\Enums\Approvals\ApprovalAnchorKind;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Approvals\ApprovalDefinitionResource;
use App\Models\ApprovalDefinition;
use App\Support\Approvals\ApprovalDefinitionValidator;
use App\Support\Authorization\TenantRoleOptions;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\TenantUserOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnchorApprovalDefinitionsController extends Controller
{
    private string $configurePermission = 'approvals.configure';

    public function __construct(
        private readonly SaveAnchorApprovalDefinitionAction $saveAnchorApprovalDefinition,
        private readonly DeleteAnchorApprovalDefinitionAction $deleteAnchorApprovalDefinition,
        private readonly ApprovalDefinitionValidator $approvalValidator,
        private readonly TenantRoleOptions $roleOptions,
        private readonly TenantTeamOptions $teamOptions,
        private readonly TenantUserOptions $userOptions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizePermission($request, $this->configurePermission);

        $definitions = [];
        $warnings = [];

        foreach (ApprovalAnchorKind::cases() as $kind) {
            $definition = $this->storedDefinition($kind);

            $definitions[$kind->value] = $definition instanceof ApprovalDefinition
                ? (new ApprovalDefinitionResource($definition))->resolve($request)
                : null;

            $warnings[$kind->value] = $this->warningsOf($kind, $definition);
        }

        return Inertia::render('approvals/Definitions', [
            'definitions' => $definitions,
            'warnings' => $warnings,
            'roleOptions' => $this->roleOptions->assignable(),
            'teamOptions' => $this->teamOptions->forUser($user),
            'userOptions' => $this->userOptions->allForUser($user),
        ]);
    }

    public function update(Request $request, ApprovalAnchorKind $kind): RedirectResponse
    {
        $this->authorizePermission($request, $this->configurePermission);

        $this->saveAnchorApprovalDefinition->execute($kind, $request->all());

        return to_route('engine.approval-definitions.index');
    }

    public function destroy(Request $request, ApprovalAnchorKind $kind): RedirectResponse
    {
        $this->authorizePermission($request, $this->configurePermission);

        $this->deleteAnchorApprovalDefinition->execute($kind);

        return to_route('engine.approval-definitions.index');
    }

    private function storedDefinition(ApprovalAnchorKind $kind): ?ApprovalDefinition
    {
        return ApprovalDefinition::query()
            ->where('anchor_type', $kind->modelClass())
            ->whereNull('anchor_id')
            ->with('stages')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function warningsOf(ApprovalAnchorKind $kind, ?ApprovalDefinition $definition): array
    {
        if (!$definition instanceof ApprovalDefinition) {
            return [];
        }

        return $this->approvalValidator->deadEndStageWarnings($definition, __('i18n.backend.http.controllers.approvals.anchor_approval_definitions_controller.this').$kind->label());
    }
}
