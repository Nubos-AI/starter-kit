<?php

declare(strict_types=1);

use App\Http\Controllers\Aging\AgingRulesController;
use App\Http\Controllers\Api\ApiTokensController;
use App\Http\Controllers\Approvals\AnchorApprovalDefinitionsController;
use App\Http\Controllers\Approvals\ApprovalsController;
use App\Http\Controllers\Authorization\RolesController;
use App\Http\Controllers\ConfigBundle\ConfigBundleController;
use App\Http\Controllers\Dashboards\DashboardsController;
use App\Http\Controllers\Dashboards\DashboardShareOptionsController;
use App\Http\Controllers\Dashboards\DashboardSharesController;
use App\Http\Controllers\Dashboards\DashboardWidgetResultsController;
use App\Http\Controllers\Dashboards\DashboardWidgetsController;
use App\Http\Controllers\Dashboards\DefaultDashboardController;
use App\Http\Controllers\Dashboards\DefaultDashboardSelectionController;
use App\Http\Controllers\Engine\ActivityTypesController;
use App\Http\Controllers\Engine\BulkActionsController;
use App\Http\Controllers\Engine\CreatableObjectTypesController;
use App\Http\Controllers\Engine\FieldDefinitionsController;
use App\Http\Controllers\Engine\FieldGroupsController;
use App\Http\Controllers\Engine\FieldRecomputesController;
use App\Http\Controllers\Engine\FieldTypesController;
use App\Http\Controllers\Engine\MergeRulesController;
use App\Http\Controllers\Engine\ObjectTypesController;
use App\Http\Controllers\Engine\RecordActivitiesController;
use App\Http\Controllers\Engine\RecordFilesController;
use App\Http\Controllers\Engine\RecordGridsController;
use App\Http\Controllers\Engine\RecordHierarchiesController;
use App\Http\Controllers\Engine\RecordLookupController;
use App\Http\Controllers\Engine\RecordMergeController;
use App\Http\Controllers\Engine\RecordRelationsController;
use App\Http\Controllers\Engine\RecordsController;
use App\Http\Controllers\Engine\RecordTimelineController;
use App\Http\Controllers\Engine\RecordWriteController;
use App\Http\Controllers\Engine\RelationshipTypesController;
use App\Http\Controllers\Engine\ReminderTypesController;
use App\Http\Controllers\Engine\SegmentManagementController;
use App\Http\Controllers\Engine\TrashController;
use App\Http\Controllers\Export\ExportFieldPresetsController;
use App\Http\Controllers\Export\ExportsController;
use App\Http\Controllers\Formulas\FormulaBackfillsController;
use App\Http\Controllers\Goals\GoalsController;
use App\Http\Controllers\Import\ImportExecutionsController;
use App\Http\Controllers\Import\ImportMappingPresetsController;
use App\Http\Controllers\Import\ImportPageController;
use App\Http\Controllers\Import\ImportPreviewsController;
use App\Http\Controllers\Import\ImportUploadsController;
use App\Http\Controllers\Maintenance\MaintenanceLockController;
use App\Http\Controllers\Notes\RecordNotesController;
use App\Http\Controllers\Notifications\InboxesController;
use App\Http\Controllers\Notifications\NotificationPreferencesController;
use App\Http\Controllers\Notifications\NotificationRulesController;
use App\Http\Controllers\Notifications\PushSubscriptionsController;
use App\Http\Controllers\Preferences\UserPreferencesController;
use App\Http\Controllers\Promotion\PromotionRunsController;
use App\Http\Controllers\Records\RecordCollaboratorsController;
use App\Http\Controllers\Reminders\ReminderPageController;
use App\Http\Controllers\Reminders\ReminderTasksController;
use App\Http\Controllers\Reports\ReportExportsController;
use App\Http\Controllers\Reports\ReportPreviewController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\Reports\ReportSegmentPrefillController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Segments\AdminDefaultSegmentsController;
use App\Http\Controllers\Segments\SegmentDeepLinksController;
use App\Http\Controllers\Segments\SegmentsController;
use App\Http\Controllers\Segments\SegmentShareOptionsController;
use App\Http\Controllers\Segments\SegmentSharesController;
use App\Http\Controllers\Skills\SkillsController;
use App\Http\Controllers\Teams\TeamAccessRulesController;
use App\Http\Controllers\Teams\TeamsController;
use App\Http\Controllers\Tenancy\PreferencePolicyController;
use App\Http\Controllers\Users\UserInvitationsController;
use App\Http\Controllers\Users\UserOptionsController;
use App\Http\Controllers\Users\UserPasswordsController;
use App\Http\Controllers\Users\UsersController;
use App\Http\Controllers\Users\UserStatusesController;
use App\Http\Controllers\Watchers\WatchersController;
use App\Http\Controllers\Webhooks\WebhookSubscriptionsController;
use App\Support\Engine\RecordRouteResolver;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', DefaultDashboardController::class)->name('dashboard');
Route::put('dashboard/default/{dashboard}', DefaultDashboardSelectionController::class)->whereUlid('dashboard')->name('dashboard.default');

Route::get('engine/search', SearchController::class)->name('engine.search');

Route::get('engine/object-types/creatable', CreatableObjectTypesController::class)->name('engine.object-types.creatable');

Route::get('engine/object-types', [ObjectTypesController::class, 'index'])->middleware('permission:object-types.view')->name('engine.object-types.index');
Route::get('engine/object-types/create', [ObjectTypesController::class, 'create'])->middleware('permission:object-types.create')->name('engine.object-types.create');
Route::post('engine/object-types', [ObjectTypesController::class, 'store'])->middleware('permission:object-types.create')->name('engine.object-types.store');
Route::get('engine/object-types/{objectType:slug}/edit', [ObjectTypesController::class, 'edit'])->middleware('permission:object-types.view')->name('engine.object-types.edit');
Route::get('engine/object-types/{objectType:slug}/edit/fields', [ObjectTypesController::class, 'fields'])->middleware(['permission:object-types.view', 'capability:custom_fields'])->name('engine.object-types.edit.fields');
Route::get('engine/object-types/{objectType:slug}/edit/aging-rules', [ObjectTypesController::class, 'agingRules'])->middleware(['permission:object-types.view', 'capability:aging'])->name('engine.object-types.edit.aging-rules');
Route::get('engine/object-types/{objectType:slug}/edit/merge-rules', [ObjectTypesController::class, 'mergeRules'])->middleware(['permission:object-types.view', 'capability:merge'])->name('engine.object-types.edit.merge-rules');
Route::get('engine/object-types/{objectType:slug}/edit/permissions', [ObjectTypesController::class, 'permissions'])->middleware('permission:object-types.view')->name('engine.object-types.edit.permissions');
Route::put('engine/object-types/{objectType:slug}', [ObjectTypesController::class, 'update'])->middleware('permission:object-types.update')->name('engine.object-types.update');
Route::post('engine/object-types/bulk-delete', [ObjectTypesController::class, 'bulkDestroy'])->middleware('permission:object-types.delete')->name('engine.object-types.bulkDestroy');
Route::delete('engine/object-types/{objectType:slug}', [ObjectTypesController::class, 'destroy'])->middleware('permission:object-types.delete')->name('engine.object-types.destroy');

Route::post('engine/object-types/{objectType:slug}/fields', [FieldDefinitionsController::class, 'store'])->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.fields.store');
Route::put('engine/object-types/{objectType:slug}/fields/{field}', [FieldDefinitionsController::class, 'update'])->whereUlid('field')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.fields.update');
Route::post('engine/object-types/{objectType:slug}/fields/{field}/recompute', [FieldRecomputesController::class, 'store'])->whereUlid('field')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.fields.recompute');
Route::put('engine/object-types/{objectType:slug}/fields/{field}/type', [FieldTypesController::class, 'update'])->whereUlid('field')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.fields.type');
Route::delete('engine/object-types/{objectType:slug}/fields/{field}', [FieldDefinitionsController::class, 'destroy'])->whereUlid('field')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.fields.destroy');

Route::post('engine/object-types/{objectType:slug}/field-groups', [FieldGroupsController::class, 'store'])->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.field-groups.store');
Route::put('engine/object-types/{objectType:slug}/field-groups/{fieldGroup}', [FieldGroupsController::class, 'update'])->whereUlid('fieldGroup')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.field-groups.update');
Route::delete('engine/object-types/{objectType:slug}/field-groups/{fieldGroup}', [FieldGroupsController::class, 'destroy'])->whereUlid('fieldGroup')->middleware(['permission:object-types.update', 'capability:custom_fields'])->name('engine.object-types.field-groups.destroy');

Route::get('engine/approvals', [ApprovalsController::class, 'index'])->name('engine.approvals.index');
Route::get('engine/approvals/{approval}', [ApprovalsController::class, 'show'])->whereUlid('approval')->name('engine.approvals.show');
Route::post('engine/approvals/{approval}/decide', [ApprovalsController::class, 'decide'])->whereUlid('approval')->name('engine.approvals.decide');
Route::post('engine/approvals/{approval}/cancel', [ApprovalsController::class, 'cancel'])->whereUlid('approval')->name('engine.approvals.cancel');

Route::get('engine/approval-definitions', [AnchorApprovalDefinitionsController::class, 'index'])->middleware(['live-tenant', 'permission:approvals.configure'])->name('engine.approval-definitions.index');
Route::put('engine/approval-definitions/{kind}', [AnchorApprovalDefinitionsController::class, 'update'])->middleware(['live-tenant', 'permission:approvals.configure'])->name('engine.approval-definitions.update');
Route::delete('engine/approval-definitions/{kind}', [AnchorApprovalDefinitionsController::class, 'destroy'])->middleware(['live-tenant', 'permission:approvals.configure'])->name('engine.approval-definitions.destroy');

Route::get('engine/object-types/{objectType:slug}/aging-rules', [AgingRulesController::class, 'index'])->middleware(['permission:object-types.view', 'capability:aging'])->name('engine.object-types.aging-rules.index');
Route::post('engine/object-types/{objectType:slug}/aging-rules', [AgingRulesController::class, 'store'])->middleware(['permission:object-types.update', 'capability:aging'])->name('engine.object-types.aging-rules.store');
Route::post('engine/object-types/{objectType:slug}/aging-rules/bulk-delete', [AgingRulesController::class, 'bulkDestroy'])->middleware(['permission:object-types.update', 'capability:aging'])->name('engine.object-types.aging-rules.bulkDestroy');
Route::put('engine/object-types/{objectType:slug}/aging-rules/{agingRule}', [AgingRulesController::class, 'update'])->whereUlid('agingRule')->middleware(['permission:object-types.update', 'capability:aging'])->name('engine.object-types.aging-rules.update');
Route::delete('engine/object-types/{objectType:slug}/aging-rules/{agingRule}', [AgingRulesController::class, 'destroy'])->whereUlid('agingRule')->middleware(['permission:object-types.update', 'capability:aging'])->name('engine.object-types.aging-rules.destroy');

Route::get('engine/object-types/{objectType:slug}/merge-rules', [MergeRulesController::class, 'index'])->middleware(['permission:object-types.view', 'capability:merge'])->name('engine.object-types.merge-rules.index');
Route::get('engine/object-types/{objectType:slug}/merge-rules/create', [MergeRulesController::class, 'create'])->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.create');
Route::get('engine/object-types/{objectType:slug}/merge-rules/{mergeRule}/edit', [MergeRulesController::class, 'edit'])->whereUlid('mergeRule')->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.edit');
Route::post('engine/object-types/{objectType:slug}/merge-rules', [MergeRulesController::class, 'store'])->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.store');
Route::post('engine/object-types/{objectType:slug}/merge-rules/bulk-delete', [MergeRulesController::class, 'bulkDestroy'])->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.bulkDestroy');
Route::put('engine/object-types/{objectType:slug}/merge-rules/{mergeRule}', [MergeRulesController::class, 'update'])->whereUlid('mergeRule')->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.update');
Route::delete('engine/object-types/{objectType:slug}/merge-rules/{mergeRule}', [MergeRulesController::class, 'destroy'])->whereUlid('mergeRule')->middleware(['permission:object-types.update', 'capability:merge'])->name('engine.object-types.merge-rules.destroy');

Route::get('engine/personalization', [PreferencePolicyController::class, 'edit'])->middleware('permission:organisation.view')->name('engine.personalization.edit');
Route::put('engine/personalization', [PreferencePolicyController::class, 'update'])->middleware('permission:organisation.update')->name('engine.personalization.update');

Route::get('engine/promotions', [PromotionRunsController::class, 'index'])->middleware('live-tenant')->name('engine.promotions.index');
Route::get('engine/promotions/create', [PromotionRunsController::class, 'create'])->middleware('live-tenant')->name('engine.promotions.create');
Route::post('engine/promotions', [PromotionRunsController::class, 'store'])->middleware('live-tenant')->name('engine.promotions.store');
Route::get('engine/promotions/{promotionRun}', [PromotionRunsController::class, 'show'])->whereUlid('promotionRun')->middleware('live-tenant')->name('engine.promotions.show');
Route::put('engine/promotions/{promotionRun}', [PromotionRunsController::class, 'update'])->whereUlid('promotionRun')->middleware('live-tenant')->name('engine.promotions.update');
Route::post('engine/promotions/{promotionRun}/submit', [PromotionRunsController::class, 'submit'])->whereUlid('promotionRun')->middleware('live-tenant')->name('engine.promotions.submit');
Route::post('engine/promotions/{promotionRun}/rollback', [PromotionRunsController::class, 'rollback'])->whereUlid('promotionRun')->middleware('live-tenant')->name('engine.promotions.rollback');

Route::get('engine/config', [ConfigBundleController::class, 'index'])->name('engine.config.index');
Route::get('engine/config/export', [ConfigBundleController::class, 'export'])->middleware('permission:config.export')->name('engine.config.export');
Route::post('engine/config/import', [ConfigBundleController::class, 'upload'])->middleware('permission:config.import')->name('engine.config.import.upload');
Route::get('engine/config/import/{run}/placeholders', [ConfigBundleController::class, 'placeholders'])->whereUlid('run')->middleware('permission:config.import')->name('engine.config.import.placeholders');
Route::post('engine/config/import/{run}/placeholders', [ConfigBundleController::class, 'storePlaceholders'])->whereUlid('run')->middleware('permission:config.import')->name('engine.config.import.placeholders.store');

Route::get('engine/roles', [RolesController::class, 'index'])->middleware('permission:roles.view')->name('engine.roles.index');
Route::get('engine/roles/create', [RolesController::class, 'create'])->middleware('can:create,App\Models\Role')->name('engine.roles.create');
Route::post('engine/roles', [RolesController::class, 'store'])->middleware('can:create,App\Models\Role')->name('engine.roles.store');
Route::get('engine/roles/{role}/edit', [RolesController::class, 'edit'])->whereUlid('role')->middleware('can:update,role')->name('engine.roles.edit');
Route::put('engine/roles/{role}', [RolesController::class, 'update'])->whereUlid('role')->middleware('can:update,role')->name('engine.roles.update');
Route::delete('engine/roles/{role}', [RolesController::class, 'destroy'])->whereUlid('role')->middleware('can:delete,role')->name('engine.roles.destroy');
Route::put('engine/roles/{role}/permissions', [RolesController::class, 'updatePermissions'])->whereUlid('role')->middleware('can:update,role')->name('engine.roles.permissions.update');

Route::get('engine/users', [UsersController::class, 'index'])->middleware('permission:members.view')->name('engine.users.index');
Route::get('engine/users/options', UserOptionsController::class)->middleware('permission:members.view')->name('engine.users.options');
Route::get('engine/users/invite', [UserInvitationsController::class, 'create'])->middleware('permission:members.invite')->name('engine.users.invite.create');
Route::post('engine/users/invite', [UserInvitationsController::class, 'store'])->middleware('permission:members.invite')->name('engine.users.invite.store');
Route::post('engine/users/{user}/invitation/resend', [UserInvitationsController::class, 'resend'])->whereUlid('user')->middleware('permission:members.invite')->name('engine.users.invite.resend');
Route::get('engine/users/{user}/edit', [UsersController::class, 'edit'])->whereUlid('user')->name('engine.users.edit');
Route::put('engine/users/{user}', [UsersController::class, 'update'])->whereUlid('user')->name('engine.users.update');
Route::delete('engine/users/{user}', [UsersController::class, 'destroy'])->whereUlid('user')->name('engine.users.destroy');
Route::put('engine/users/{user}/password', [UserPasswordsController::class, 'update'])->whereUlid('user')->name('engine.users.password.update');
Route::post('engine/users/{user}/password-reset-link', [UserPasswordsController::class, 'sendResetLink'])->whereUlid('user')->name('engine.users.password.reset-link');
Route::put('engine/users/{user}/status', UserStatusesController::class)->whereUlid('user')->name('engine.users.status.update');

Route::get('engine/teams/{team}/access-rules', [TeamAccessRulesController::class, 'index'])->whereUlid('team')->middleware('permission:teams.manage')->name('engine.teams.access-rules.index');
Route::put('engine/teams/{team}/access-rules/{objectType}', [TeamAccessRulesController::class, 'update'])->whereUlid('team')->whereUlid('objectType')->middleware(['permission:teams.manage', 'capability:records'])->name('engine.teams.access-rules.update');
Route::delete('engine/teams/{team}/access-rules/{objectType}', [TeamAccessRulesController::class, 'destroy'])->whereUlid('team')->whereUlid('objectType')->middleware(['permission:teams.manage', 'capability:records'])->name('engine.teams.access-rules.destroy');

Route::get('engine/teams', [TeamsController::class, 'index'])->middleware('permission:teams.view')->name('engine.teams.index');
Route::get('engine/teams/create', [TeamsController::class, 'create'])->middleware('permission:teams.create')->name('engine.teams.create');
Route::post('engine/teams', [TeamsController::class, 'store'])->middleware('permission:teams.create')->name('engine.teams.store');
Route::get('engine/teams/{team}/edit', [TeamsController::class, 'edit'])->whereUlid('team')->middleware('permission:teams.update')->name('engine.teams.edit');
Route::put('engine/teams/{team}', [TeamsController::class, 'update'])->whereUlid('team')->middleware('permission:teams.update')->name('engine.teams.update');
Route::post('engine/teams/bulk-delete', [TeamsController::class, 'bulkDestroy'])->middleware('permission:teams.delete')->name('engine.teams.bulkDestroy');
Route::delete('engine/teams/{team}', [TeamsController::class, 'destroy'])->whereUlid('team')->middleware('permission:teams.delete')->name('engine.teams.destroy');
Route::put('engine/teams/{team}/reparent', [TeamsController::class, 'reparent'])->whereUlid('team')->middleware('permission:teams.reparent')->name('engine.teams.reparent');

Route::get('engine/relationship-types', [RelationshipTypesController::class, 'index'])->middleware('permission:object-types.view')->name('engine.relationship-types.index');
Route::get('engine/relationship-types/create', [RelationshipTypesController::class, 'create'])->middleware('permission:object-types.create')->name('engine.relationship-types.create');
Route::post('engine/relationship-types', [RelationshipTypesController::class, 'store'])->middleware('permission:object-types.create')->name('engine.relationship-types.store');
Route::get('engine/relationship-types/{relationshipType}/edit', [RelationshipTypesController::class, 'edit'])->whereUlid('relationshipType')->middleware('permission:object-types.view')->name('engine.relationship-types.edit');
Route::put('engine/relationship-types/{relationshipType}', [RelationshipTypesController::class, 'update'])->whereUlid('relationshipType')->middleware('permission:object-types.update')->name('engine.relationship-types.update');
Route::post('engine/relationship-types/bulk-delete', [RelationshipTypesController::class, 'bulkDestroy'])->middleware('permission:object-types.delete')->name('engine.relationship-types.bulkDestroy');
Route::delete('engine/relationship-types/{relationshipType}', [RelationshipTypesController::class, 'destroy'])->whereUlid('relationshipType')->middleware('permission:object-types.delete')->name('engine.relationship-types.destroy');

Route::get('engine/reminder-types', [ReminderTypesController::class, 'index'])->middleware('permission:reminder-types.view')->name('engine.reminder-types.index');
Route::get('engine/reminder-types/create', [ReminderTypesController::class, 'create'])->middleware('permission:reminder-types.create')->name('engine.reminder-types.create');
Route::post('engine/reminder-types', [ReminderTypesController::class, 'store'])->middleware('permission:reminder-types.create')->name('engine.reminder-types.store');
Route::get('engine/reminder-types/{reminderType}/edit', [ReminderTypesController::class, 'edit'])->whereUlid('reminderType')->middleware('permission:reminder-types.view')->name('engine.reminder-types.edit');
Route::put('engine/reminder-types/{reminderType}', [ReminderTypesController::class, 'update'])->whereUlid('reminderType')->middleware('permission:reminder-types.update')->name('engine.reminder-types.update');
Route::post('engine/reminder-types/bulk-delete', [ReminderTypesController::class, 'bulkDestroy'])->middleware('permission:reminder-types.delete')->name('engine.reminder-types.bulkDestroy');
Route::delete('engine/reminder-types/{reminderType}', [ReminderTypesController::class, 'destroy'])->whereUlid('reminderType')->middleware('permission:reminder-types.delete')->name('engine.reminder-types.destroy');

Route::get('engine/skills', [SkillsController::class, 'index'])->middleware('permission:skills.view')->name('engine.skills.index');
Route::get('engine/skills/create', [SkillsController::class, 'create'])->middleware('permission:skills.create')->name('engine.skills.create');
Route::post('engine/skills', [SkillsController::class, 'store'])->middleware('permission:skills.create')->name('engine.skills.store');
Route::get('engine/skills/{skill}/edit', [SkillsController::class, 'edit'])->whereUlid('skill')->middleware('permission:skills.view')->name('engine.skills.edit');
Route::put('engine/skills/{skill}', [SkillsController::class, 'update'])->whereUlid('skill')->middleware('permission:skills.update')->name('engine.skills.update');
Route::post('engine/skills/bulk-delete', [SkillsController::class, 'bulkDestroy'])->middleware('permission:skills.delete')->name('engine.skills.bulkDestroy');
Route::delete('engine/skills/{skill}', [SkillsController::class, 'destroy'])->whereUlid('skill')->middleware('permission:skills.delete')->name('engine.skills.destroy');

Route::get('engine/segment-management', [SegmentManagementController::class, 'index'])->name('engine.segments.manage.index');
Route::get('engine/segment-management/create', [SegmentManagementController::class, 'create'])->name('engine.segments.manage.create');
Route::post('engine/segment-management', [SegmentManagementController::class, 'store'])->name('engine.segments.manage.store');
Route::get('engine/segment-management/{segment}/edit', [SegmentManagementController::class, 'edit'])->whereUlid('segment')->name('engine.segments.manage.edit');
Route::put('engine/segment-management/{segment}', [SegmentManagementController::class, 'update'])->whereUlid('segment')->name('engine.segments.manage.update');
Route::post('engine/segment-management/bulk-delete', [SegmentManagementController::class, 'bulkDestroy'])->name('engine.segments.manage.bulkDestroy');
Route::delete('engine/segment-management/{segment}', [SegmentManagementController::class, 'destroy'])->whereUlid('segment')->name('engine.segments.manage.destroy');

Route::get('engine/trash', [TrashController::class, 'index'])->name('engine.trash.index');
Route::put('engine/trash/{id}/restore', [TrashController::class, 'restore'])->whereUlid('id')->name('engine.trash.restore');
Route::post('engine/trash/bulk-delete', [TrashController::class, 'bulkDestroy'])->name('engine.trash.bulkDestroy');
Route::delete('engine/trash/{id}', [TrashController::class, 'destroy'])->whereUlid('id')->name('engine.trash.destroy');

Route::get('records/{objectType:slug}/create', [RecordsController::class, 'create'])->middleware(['permission:{objectType}.create', 'capability:records'])->name('engine.records.create');
Route::get('records/{objectType:slug}/import', [ImportPageController::class, 'wizard'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.page');
Route::get('records/{objectType:slug}/import/history', [ImportPageController::class, 'history'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.history-page');
Route::get('records/{record}/edit', [RecordsController::class, 'edit'])->where('record', RecordRouteResolver::routePattern())->middleware('capability:records')->name('engine.records.edit');
Route::get('records/{record}', [RecordsController::class, 'show'])->where('record', RecordRouteResolver::routePattern())->middleware('capability:records')->name('engine.records.show');
Route::get('records/{record}/timeline', RecordTimelineController::class)->where('record', RecordRouteResolver::routePattern())->middleware('capability:timeline')->name('engine.records.timeline');

Route::get('engine/object-types/{objectType:slug}/records/lookup', RecordLookupController::class)->middleware(['permission:{objectType}.view', 'capability:lookup'])->name('engine.records.lookup');
Route::post('engine/records/{objectType:slug}', [RecordWriteController::class, 'store'])->middleware(['permission:{objectType}.create', 'capability:records'])->name('engine.records.store');
Route::put('engine/records/{record}', [RecordWriteController::class, 'update'])->whereUlid('record')->middleware(['can:update,record', 'capability:records'])->name('engine.records.update');
Route::post('engine/records/{record}/duplicate', [RecordWriteController::class, 'duplicate'])->whereUlid('record')->middleware(['can:view,record', 'permission:{record}.create', 'capability:records'])->name('engine.records.duplicate');
Route::get('records/{record}/merge', [RecordMergeController::class, 'show'])->whereUlid('record')->middleware('capability:merge')->name('engine.records.merge.show');
Route::get('engine/records/{record}/merge/candidates', [RecordMergeController::class, 'candidates'])->whereUlid('record')->middleware('capability:merge')->name('engine.records.merge.candidates');
Route::post('engine/records/{record}/merge/preview', [RecordMergeController::class, 'preview'])->whereUlid('record')->middleware('capability:merge')->name('engine.records.merge.preview');
Route::post('engine/records/{record}/merge', [RecordMergeController::class, 'store'])->whereUlid('record')->middleware('capability:merge')->name('engine.records.merge.store');
Route::post('engine/records/{record}/merge/{merge}/undo', [RecordMergeController::class, 'undo'])->whereUlid('record')->whereUlid('merge')->middleware('capability:merge')->name('engine.records.merge.undo');
Route::delete('engine/records/{record}', [RecordWriteController::class, 'destroy'])->whereUlid('record')->middleware('capability:records')->name('engine.records.destroy');
Route::patch('engine/records/{record}/cell', [RecordWriteController::class, 'updateCell'])->whereUlid('record')->middleware(['can:update,record', 'capability:records'])->name('engine.records.cell');
Route::put('engine/records/{record}/parent', [RecordHierarchiesController::class, 'update'])->whereUlid('record')->middleware('capability:hierarchy')->name('engine.records.parent');
Route::get('engine/records/{record}/parent-candidates', [RecordHierarchiesController::class, 'candidates'])->whereUlid('record')->middleware('capability:hierarchy')->name('engine.records.parent-candidates');
Route::get('engine/records/{record}/relations', [RecordRelationsController::class, 'index'])->whereUlid('record')->middleware(['can:view,record', 'query-budget', 'capability:relations'])->name('engine.records.relations.index');
Route::post('engine/records/{record}/relations/entries', [RecordRelationsController::class, 'entries'])->whereUlid('record')->middleware(['can:view,record', 'query-budget', 'capability:relations'])->name('engine.records.relations.entries');
Route::post('engine/records/{record}/relations/candidates', [RecordRelationsController::class, 'candidates'])->whereUlid('record')->middleware(['can:view,record', 'query-budget', 'capability:relations'])->name('engine.records.relations.candidates');
Route::post('engine/records/{record}/relations', [RecordRelationsController::class, 'store'])->whereUlid('record')->middleware(['can:update,record', 'capability:relations'])->name('engine.records.relations.store');
Route::delete('engine/records/{record}/relations/{link}', [RecordRelationsController::class, 'destroy'])->whereUlid('record')->whereUlid('link')->middleware(['can:update,record', 'capability:relations'])->name('engine.records.relations.destroy');
Route::get('engine/records/{record}/collaborator-candidates', [RecordCollaboratorsController::class, 'candidates'])->whereUlid('record')->middleware('can:update,record')->name('engine.records.collaborator-candidates');

Route::get('records/{objectType:slug}', [RecordGridsController::class, 'index'])->middleware(['permission:{objectType}.view', 'query-budget', 'capability:records'])->name('engine.records.index');
Route::post('records/{objectType:slug}/grid', [RecordGridsController::class, 'grid'])->middleware(['permission:{objectType}.view', 'query-budget', 'capability:records'])->name('engine.records.grid');

Route::post('engine/records/{objectType:slug}/bulk', [BulkActionsController::class, 'store'])->middleware('capability:bulk_actions')->name('engine.records.bulk');
Route::get('engine/batches/{batchId}', [BulkActionsController::class, 'show'])->whereUuid('batchId')->name('engine.batches.show');
Route::get('engine/batches/{batchId}/download', [BulkActionsController::class, 'download'])->whereUuid('batchId')->name('engine.batches.download');

Route::get('engine/formula-backfills/{objectType:slug}', [FormulaBackfillsController::class, 'show'])->middleware(['can:update,objectType', 'capability:custom_fields'])->name('engine.formula-backfills.show');
Route::post('engine/formula-backfills/{backfillRun}/cancel', [FormulaBackfillsController::class, 'cancel'])->whereUlid('backfillRun')->name('engine.formula-backfills.cancel');

Route::get('engine/preferences', [UserPreferencesController::class, 'show'])->name('engine.preferences.show');
Route::put('engine/preferences', [UserPreferencesController::class, 'update'])->name('engine.preferences.update');

Route::get('engine/segments/{objectType:slug}/deep-link', SegmentDeepLinksController::class)->middleware(['permission:{objectType}.view', 'capability:records'])->name('engine.segments.deep-link');

Route::post('engine/segments/defaults', [AdminDefaultSegmentsController::class, 'store'])->name('engine.segments.defaults.store');
Route::delete('engine/segments/defaults/{segment}', [AdminDefaultSegmentsController::class, 'destroy'])->whereUlid('segment')->name('engine.segments.defaults.destroy');

Route::get('engine/segments', [SegmentsController::class, 'index'])->name('engine.segments.index');
Route::post('engine/segments', [SegmentsController::class, 'store'])->name('engine.segments.store');
Route::get('engine/segments/{segment}', [SegmentsController::class, 'show'])->name('engine.segments.show');
Route::put('engine/segments/{segment}', [SegmentsController::class, 'update'])->whereUlid('segment')->name('engine.segments.update');
Route::delete('engine/segments/{segment}', [SegmentsController::class, 'destroy'])->whereUlid('segment')->name('engine.segments.destroy');

Route::get('engine/segments/{segment}/shares', [SegmentSharesController::class, 'index'])->whereUlid('segment')->name('engine.segments.shares.index');
Route::get('engine/segments/{segment}/share-options', SegmentShareOptionsController::class)->whereUlid('segment')->name('engine.segments.shareOptions');
Route::post('engine/segments/{segment}/shares', [SegmentSharesController::class, 'store'])->whereUlid('segment')->name('engine.segments.shares.store');
Route::delete('engine/segments/{segment}/shares/{share}', [SegmentSharesController::class, 'destroy'])->whereUlid('segment')->whereUlid('share')->name('engine.segments.shares.destroy');

Route::get('engine/webhooks', [WebhookSubscriptionsController::class, 'index'])->name('engine.webhooks.index');
Route::get('engine/webhooks/create', [WebhookSubscriptionsController::class, 'create'])->name('engine.webhooks.create');
Route::post('engine/webhooks', [WebhookSubscriptionsController::class, 'store'])->name('engine.webhooks.store');
Route::post('engine/webhooks/bulk-delete', [WebhookSubscriptionsController::class, 'bulkDestroy'])->name('engine.webhooks.bulkDestroy');
Route::get('engine/webhooks/{subscription}/edit', [WebhookSubscriptionsController::class, 'edit'])->whereUlid('subscription')->name('engine.webhooks.edit');
Route::put('engine/webhooks/{subscription}', [WebhookSubscriptionsController::class, 'update'])->whereUlid('subscription')->name('engine.webhooks.update');
Route::post('engine/webhooks/{subscription}/rotate', [WebhookSubscriptionsController::class, 'rotateSecret'])->whereUlid('subscription')->name('engine.webhooks.rotate');
Route::post('engine/webhooks/{subscription}/recheck', [WebhookSubscriptionsController::class, 'recheck'])->whereUlid('subscription')->name('engine.webhooks.recheck');
Route::delete('engine/webhooks/{subscription}', [WebhookSubscriptionsController::class, 'destroy'])->whereUlid('subscription')->name('engine.webhooks.destroy');

Route::get('engine/api-tokens', [ApiTokensController::class, 'index'])->name('engine.api-tokens.index');
Route::get('engine/api-tokens/create', [ApiTokensController::class, 'create'])->name('engine.api-tokens.create');
Route::post('engine/api-tokens', [ApiTokensController::class, 'store'])->name('engine.api-tokens.store');
Route::post('engine/api-tokens/bulk-delete', [ApiTokensController::class, 'bulkDestroy'])->name('engine.api-tokens.bulkDestroy');
Route::post('engine/api-tokens/{token}/rotate', [ApiTokensController::class, 'rotate'])->whereNumber('token')->name('engine.api-tokens.rotate');
Route::delete('engine/api-tokens/{token}', [ApiTokensController::class, 'destroy'])->whereNumber('token')->name('engine.api-tokens.destroy');

Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
Route::get('reports/create', [ReportsController::class, 'create'])->name('reports.create');
Route::post('reports', [ReportsController::class, 'store'])->name('reports.store');
Route::post('reports/preview', ReportPreviewController::class)->name('reports.preview');
Route::get('reports/segment-prefill/{segment}', ReportSegmentPrefillController::class)->name('reports.segment-prefill');
Route::post('reports/bulk-delete', [ReportsController::class, 'bulkDestroy'])->name('reports.bulkDestroy');
Route::get('reports/{report}/edit', [ReportsController::class, 'edit'])->whereUlid('report')->name('reports.edit');
Route::put('reports/{report}', [ReportsController::class, 'update'])->whereUlid('report')->name('reports.update');
Route::delete('reports/{report}', [ReportsController::class, 'destroy'])->whereUlid('report')->name('reports.destroy');
Route::post('reports/{report}/exports', [ReportExportsController::class, 'store'])->whereUlid('report')->name('reports.exports.store');
Route::get('reports/{report}/exports/{exportJob}/download', [ReportExportsController::class, 'download'])->whereUlid('report')->whereUlid('exportJob')->name('reports.exports.download');

Route::get('dashboards', [DashboardsController::class, 'index'])->name('dashboards.index');
Route::get('dashboards/create', [DashboardsController::class, 'create'])->name('dashboards.create');
Route::post('dashboards', [DashboardsController::class, 'store'])->name('dashboards.store');
Route::post('dashboards/bulk-delete', [DashboardsController::class, 'bulkDestroy'])->name('dashboards.bulkDestroy');
Route::get('dashboards/{dashboard}', [DashboardsController::class, 'show'])->whereUlid('dashboard')->name('dashboards.show');
Route::get('dashboards/{dashboard}/edit', [DashboardsController::class, 'edit'])->whereUlid('dashboard')->name('dashboards.edit');
Route::put('dashboards/{dashboard}', [DashboardsController::class, 'update'])->whereUlid('dashboard')->name('dashboards.update');
Route::delete('dashboards/{dashboard}', [DashboardsController::class, 'destroy'])->whereUlid('dashboard')->name('dashboards.destroy');
Route::get('dashboards/{dashboard}/share-options', DashboardShareOptionsController::class)->whereUlid('dashboard')->name('dashboards.shareOptions');
Route::get('dashboards/{dashboard}/shares', [DashboardSharesController::class, 'index'])->whereUlid('dashboard')->name('dashboards.shares.index');
Route::post('dashboards/{dashboard}/shares', [DashboardSharesController::class, 'store'])->whereUlid('dashboard')->name('dashboards.shares.store');
Route::delete('dashboards/{dashboard}/shares/{share}', [DashboardSharesController::class, 'destroy'])->whereUlid('dashboard')->whereUlid('share')->name('dashboards.shares.destroy');
Route::post('dashboards/{dashboard}/widgets', [DashboardWidgetsController::class, 'store'])->whereUlid('dashboard')->name('dashboards.widgets.store');
Route::put('dashboards/{dashboard}/widgets/layout', [DashboardWidgetsController::class, 'updateLayout'])->whereUlid('dashboard')->name('dashboards.widgets.layout');
Route::put('dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetsController::class, 'update'])->whereUlid('dashboard')->whereUlid('widget')->name('dashboards.widgets.update');
Route::delete('dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetsController::class, 'destroy'])->whereUlid('dashboard')->whereUlid('widget')->name('dashboards.widgets.destroy');
Route::post('dashboards/{dashboard}/widget-results', [DashboardWidgetResultsController::class, 'index'])->whereUlid('dashboard')->name('dashboards.widgets.results.index');
Route::post('dashboards/{dashboard}/widget-results/{widget}', [DashboardWidgetResultsController::class, 'show'])->whereUlid('dashboard')->whereUlid('widget')->name('dashboards.widgets.results.show');

Route::get('goals', [GoalsController::class, 'index'])->name('goals.index');
Route::get('goals/create', [GoalsController::class, 'create'])->name('goals.create');
Route::post('goals', [GoalsController::class, 'store'])->name('goals.store');
Route::post('goals/bulk-delete', [GoalsController::class, 'bulkDestroy'])->name('goals.bulkDestroy');
Route::get('goals/{goal}/edit', [GoalsController::class, 'edit'])->whereUlid('goal')->name('goals.edit');
Route::put('goals/{goal}', [GoalsController::class, 'update'])->whereUlid('goal')->name('goals.update');
Route::delete('goals/{goal}', [GoalsController::class, 'destroy'])->whereUlid('goal')->name('goals.destroy');

Route::get('notifications/inbox', [InboxesController::class, 'index'])->name('notifications.inbox.index');
Route::get('notifications/inbox/unread-count', [InboxesController::class, 'unreadCount'])->name('notifications.inbox.unreadCount');
Route::patch('notifications/inbox/{inbox}/read', [InboxesController::class, 'markRead'])->whereUlid('inbox')->name('notifications.inbox.markRead');
Route::patch('notifications/inbox/{inbox}/unread', [InboxesController::class, 'markUnread'])->whereUlid('inbox')->name('notifications.inbox.markUnread');
Route::patch('notifications/inbox/{inbox}/snooze', [InboxesController::class, 'snooze'])->whereUlid('inbox')->name('notifications.inbox.snooze');
Route::patch('notifications/inbox/{inbox}/archive', [InboxesController::class, 'archive'])->whereUlid('inbox')->name('notifications.inbox.archive');
Route::delete('notifications/inbox/{inbox}', [InboxesController::class, 'destroy'])->whereUlid('inbox')->name('notifications.inbox.destroy');

Route::patch('notifications/preferences', [NotificationPreferencesController::class, 'update'])->name('notifications.preferences.update');
Route::patch('notifications/tenant-defaults', [NotificationPreferencesController::class, 'updateTenantDefaults'])->name('notifications.tenantDefaults.update');

Route::post('notifications/push-subscriptions', [PushSubscriptionsController::class, 'store'])->name('notifications.push.store');
Route::delete('notifications/push-subscriptions', [PushSubscriptionsController::class, 'destroy'])->name('notifications.push.destroy');

Route::get('reminders', [ReminderPageController::class, 'index'])->name('reminders.page');
Route::get('reminders/my-open', [ReminderTasksController::class, 'myOpen'])->name('reminders.myOpen');
Route::get('reminders/for-record/{record}', [ReminderTasksController::class, 'forRecord'])->whereUlid('record')->name('reminders.forRecord');
Route::post('reminders', [ReminderTasksController::class, 'store'])->name('reminders.store');
Route::post('reminders/bulk-delete', [ReminderTasksController::class, 'bulkDestroy'])->name('reminders.bulkDestroy');
Route::put('reminders/{reminder}', [ReminderTasksController::class, 'update'])->whereUlid('reminder')->name('reminders.update');
Route::patch('reminders/{reminder}/complete', [ReminderTasksController::class, 'complete'])->whereUlid('reminder')->name('reminders.complete');
Route::delete('reminders/{reminder}', [ReminderTasksController::class, 'destroy'])->whereUlid('reminder')->name('reminders.destroy');

Route::get('records/{record}/watchers', [WatchersController::class, 'index'])->whereUlid('record')->name('watchers.index');
Route::put('records/{record}/watchers', [WatchersController::class, 'sync'])->whereUlid('record')->name('watchers.sync');
Route::post('records/{record}/watchers/follow', [WatchersController::class, 'follow'])->whereUlid('record')->name('watchers.follow');
Route::delete('records/{record}/watchers/follow', [WatchersController::class, 'unfollow'])->whereUlid('record')->name('watchers.unfollow');
Route::post('records/{record}/watchers/{user}', [WatchersController::class, 'addWatcher'])->whereUlid('record')->whereUlid('user')->name('watchers.add');

Route::get('records/{record}/notes', [RecordNotesController::class, 'index'])->whereUlid('record')->name('notes.index');
Route::get('records/{record}/files', [RecordFilesController::class, 'index'])->withTrashed()->whereUlid('record')->name('files.index');
Route::post('records/{record}/files', [RecordFilesController::class, 'store'])->middleware('throttle:10,1')->whereUlid('record')->name('files.store');
Route::get('records/{record}/files/{attachment}', [RecordFilesController::class, 'show'])->withTrashed()->whereUlid('record')->whereUlid('attachment')->name('files.show');
Route::delete('records/{record}/files/{attachment}', [RecordFilesController::class, 'destroy'])->whereUlid('record')->whereUlid('attachment')->name('files.destroy');
Route::post('records/{record}/notes', [RecordNotesController::class, 'store'])->whereUlid('record')->name('notes.store');
Route::put('notes/{note}', [RecordNotesController::class, 'update'])->whereUlid('note')->name('notes.update');
Route::delete('notes/{note}', [RecordNotesController::class, 'destroy'])->whereUlid('note')->name('notes.destroy');

Route::post('engine/import/{objectType:slug}/upload', [ImportUploadsController::class, 'store'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.upload');
Route::post('engine/import/{objectType:slug}/preview', [ImportPreviewsController::class, 'preview'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.preview');
Route::get('engine/import/{objectType:slug}/presets', [ImportMappingPresetsController::class, 'index'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.presets.index');
Route::post('engine/import/{objectType:slug}/presets', [ImportMappingPresetsController::class, 'store'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.presets.store');
Route::put('engine/import/{objectType:slug}/presets/{preset}', [ImportMappingPresetsController::class, 'update'])->whereUlid('preset')->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.presets.update');
Route::delete('engine/import/{objectType:slug}/presets/{preset}', [ImportMappingPresetsController::class, 'destroy'])->whereUlid('preset')->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.presets.destroy');

Route::post('engine/import/{objectType:slug}/execute', [ImportExecutionsController::class, 'execute'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.execute');
Route::get('engine/import/{objectType:slug}/history', [ImportExecutionsController::class, 'history'])->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.history');
Route::get('engine/import/{objectType:slug}/status/{batch}', [ImportExecutionsController::class, 'status'])->whereUlid('batch')->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.status');
Route::get('engine/import/{objectType:slug}/error-report/{importJob}', [ImportExecutionsController::class, 'errorReport'])->whereUlid('importJob')->middleware(['permission:{objectType}.import', 'capability:import'])->name('engine.import.error-report');

Route::post('engine/export/{objectType:slug}', [ExportsController::class, 'store'])->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.store');
Route::get('engine/export/{objectType:slug}/history', [ExportsController::class, 'history'])->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.history');
Route::get('engine/export/{objectType:slug}/status/{batch}', [ExportsController::class, 'status'])->whereUlid('batch')->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.status');
Route::get('engine/export/{objectType:slug}/download/{exportJob}', [ExportsController::class, 'download'])->whereUlid('exportJob')->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.download');
Route::get('engine/export/{objectType:slug}/presets', [ExportFieldPresetsController::class, 'index'])->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.presets.index');
Route::post('engine/export/{objectType:slug}/presets', [ExportFieldPresetsController::class, 'store'])->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.presets.store');
Route::put('engine/export/{objectType:slug}/presets/{preset}', [ExportFieldPresetsController::class, 'update'])->whereUlid('preset')->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.presets.update');
Route::delete('engine/export/{objectType:slug}/presets/{preset}', [ExportFieldPresetsController::class, 'destroy'])->whereUlid('preset')->middleware(['permission:{objectType}.export', 'capability:export'])->name('engine.export.presets.destroy');

Route::get('engine/maintenance', [MaintenanceLockController::class, 'show'])->middleware('permission:maintenance.manage')->name('engine.maintenance.show');
Route::post('engine/maintenance', [MaintenanceLockController::class, 'store'])->middleware('permission:maintenance.manage')->name('engine.maintenance.store');
Route::delete('engine/maintenance', [MaintenanceLockController::class, 'destroy'])->middleware('permission:maintenance.manage')->name('engine.maintenance.destroy');

Route::post('notification-rules/preview', [NotificationRulesController::class, 'preview'])->name('notification-rules.preview');
Route::post('notification-rules', [NotificationRulesController::class, 'store'])->name('notification-rules.store');
Route::get('notification-rules/{objectType:slug}', [NotificationRulesController::class, 'index'])->middleware(['permission:{objectType}.rules.manage', 'capability:records'])->name('notification-rules.index');
Route::put('notification-rules/{rule}', [NotificationRulesController::class, 'update'])->whereUlid('rule')->name('notification-rules.update');
Route::delete('notification-rules/{rule}', [NotificationRulesController::class, 'destroy'])->whereUlid('rule')->name('notification-rules.destroy');

Route::get('engine/activity-types', [ActivityTypesController::class, 'index'])->middleware('permission:activity-types.view')->name('engine.activity-types.index');
Route::get('engine/activity-types/create', [ActivityTypesController::class, 'create'])->middleware('permission:activity-types.create')->name('engine.activity-types.create');
Route::post('engine/activity-types', [ActivityTypesController::class, 'store'])->middleware('permission:activity-types.create')->name('engine.activity-types.store');
Route::get('engine/activity-types/{activityType}/edit', [ActivityTypesController::class, 'edit'])->whereUlid('activityType')->middleware('permission:activity-types.view')->name('engine.activity-types.edit');
Route::put('engine/activity-types/{activityType}', [ActivityTypesController::class, 'update'])->whereUlid('activityType')->middleware('permission:activity-types.update')->name('engine.activity-types.update');
Route::post('engine/activity-types/bulk-delete', [ActivityTypesController::class, 'bulkDestroy'])->middleware('permission:activity-types.delete')->name('engine.activity-types.bulkDestroy');
Route::delete('engine/activity-types/{activityType}', [ActivityTypesController::class, 'destroy'])->whereUlid('activityType')->middleware('permission:activity-types.delete')->name('engine.activity-types.destroy');

Route::get('engine/records/{record}/activities', [RecordActivitiesController::class, 'index'])->withTrashed()->whereUlid('record')->name('engine.record-activities.index');
Route::post('engine/records/{record}/activities', [RecordActivitiesController::class, 'store'])->whereUlid('record')->name('engine.record-activities.store');
Route::put('engine/records/{record}/activities/{activity}', [RecordActivitiesController::class, 'update'])->whereUlid(['record', 'activity'])->name('engine.record-activities.update');
Route::delete('engine/records/{record}/activities/{activity}', [RecordActivitiesController::class, 'destroy'])->whereUlid(['record', 'activity'])->name('engine.record-activities.destroy');
