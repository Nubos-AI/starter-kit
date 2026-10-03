<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Approvals\ApprovalOutcomeHandler;
use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\Contracts\Engine\BulkDispatcherInterface;
use App\Contracts\Export\ExportDispatcherInterface;
use App\Contracts\Import\ImportDispatcherInterface;
use App\Contracts\Modules\EditorOptionsContributorInterface;
use App\Contracts\Modules\OutboundGuardInterface;
use App\Contracts\Modules\RecordCreationExtensionInterface;
use App\Contracts\Modules\RecordMutationExtensionInterface;
use App\Contracts\Modules\RecordPresentationExtensionInterface;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\Contracts\Modules\TenantProvisioningExtensionInterface;
use App\Contracts\Modules\UiModuleInterface;
use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\Models\Abstracts\TypedRecord;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\RecordLinkCascadeObserver;
use App\Policies\Engine\CustomRecordPolicy;
use App\Support\Api\ObjectTypeDocumentTransformer;
use App\Support\Approvals\ApprovalOutcomeRegistry;
use App\Support\Approvals\PromotionRunOutcomeHandler;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\TemporalBulkDispatcher;
use App\Support\Export\TemporalExportDispatcher;
use App\Support\Goals\GoalProgressCalculator;
use App\Support\Import\TemporalImportDispatcher;
use App\Support\Modules\AllowOutboundGuard;
use App\Support\Modules\AllowTenantDeletion;
use App\Support\Modules\EditorOptions;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\RecordExtensions;
use App\Support\Modules\TenantProvisioningExtensions;
use App\Support\Modules\UiModuleRegistry;
use App\Support\Promotion\TemporalPromotionDispatcher;
use App\Support\Promotion\UnavailableTenantPromotionSource;
use App\Support\Reports\ReportExpressionCompiler;
use App\Support\Reports\ReportLinkedFieldResolver;
use App\Support\Reports\ReportResultAssembler;
use App\Support\Reports\ReportRunner;
use App\Support\Teams\TeamSegment;
use App\Support\Temporal\GuardedPayloadConverter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(TenantPromotionSourceInterface::class, UnavailableTenantPromotionSource::class);
        $this->app->bindIf(OutboundGuardInterface::class, AllowOutboundGuard::class);
        $this->app->bindIf(TenantDeletionGuardInterface::class, AllowTenantDeletion::class);

        $this->app->bind(DataConverterInterface::class, fn (): DataConverter => new DataConverter(
            new NullConverter,
            new BinaryConverter,
            new ProtoJsonConverter,
            new ProtoConverter,
            new GuardedPayloadConverter,
        ));

        $this->app->scoped(RecordExtensions::class, fn (Application $app): RecordExtensions => new RecordExtensions($app->tagged(RecordCreationExtensionInterface::class), $app->tagged(RecordPresentationExtensionInterface::class), $app->tagged(RecordMutationExtensionInterface::class)));

        $this->app->scoped(TenantProvisioningExtensions::class, fn (Application $app): TenantProvisioningExtensions => new TenantProvisioningExtensions($app->tagged(TenantProvisioningExtensionInterface::class)));

        $this->app->singleton(ModuleCatalog::class);

        $this->app->scoped(ModuleRegistry::class);

        $this->app->scoped(EditorOptions::class, fn (Application $app): EditorOptions => new EditorOptions($app->tagged(EditorOptionsContributorInterface::class)));

        $this->app->scoped(UiModuleRegistry::class, fn (Application $app): UiModuleRegistry => new UiModuleRegistry($app->tagged(UiModuleInterface::class), $app->make(ModuleRegistry::class), $app->make(ModuleCatalog::class)));

        $this->app->scoped(ObjectTypeRegistry::class);
        $this->app->scoped(ObjectTypeBackingRegistry::class);
        $this->app->scoped(RowAccessEnforcement::class);
        $this->app->scoped(TeamAccessRuleResolver::class);
        $this->app->scoped(RecordAccessRuleCompiler::class);

        $this->app->scoped(ScheduleClientInterface::class, fn (Application $app): ScheduleClientInterface => ScheduleClient::create(
            serviceClient: $app->make(ServiceClientInterface::class),
            options: (new ClientOptions)->withNamespace(config('temporal.namespace')),
            converter: $app->make(DataConverterInterface::class),
        ));

        $this->app->bind(ArtifactWriterRegistry::class, fn (Application $app): ArtifactWriterRegistry => new ArtifactWriterRegistry(
            $this->resolveArtifactWriters($app, $app->make(TargetKeyResolver::class)),
        ));

        $this->app->bind(ApprovalOutcomeRegistry::class, fn (Application $app): ApprovalOutcomeRegistry => new ApprovalOutcomeRegistry([
            ...$app->tagged(ApprovalOutcomeHandler::class),
            $app->make(PromotionRunOutcomeHandler::class),
        ]));

        $this->app->when(GoalProgressCalculator::class)
            ->needs(ReportRunner::class)
            ->give(fn (Application $app): ReportRunner => new ReportRunner(
                $app->make(ReportExpressionCompiler::class),
                $app->make(RecordFilterCompiler::class),
                new ReportResultAssembler(1),
                $app->make(ReportLinkedFieldResolver::class),
            ));

        $this->app->bind(ExportDispatcherInterface::class, TemporalExportDispatcher::class);
        $this->app->bind(ImportDispatcherInterface::class, TemporalImportDispatcher::class);
        $this->app->bind(BulkDispatcherInterface::class, TemporalBulkDispatcher::class);
        $this->app->bind(PromotionDispatcherInterface::class, TemporalPromotionDispatcher::class);
    }

    public function boot(): void
    {
        URL::defaults([TeamSegment::key() => null]);

        $this->configureDefaults();
        $this->configureTenantContextPropagation();
        $this->configureAuthorization();
        $this->configureObservers();
        $this->configureObjectTypeRegistry();
        $this->configureApiDocs();
    }

    /**
     * @return list<ArtifactWriterInterface>
     */
    protected function resolveArtifactWriters(Application $app, TargetKeyResolver $targetKeys): array
    {
        $configured = config('engine.config_bundle.writers');
        $contract = ArtifactWriterInterface::class;

        if (!is_array($configured)) {
            $type = get_debug_type($configured);

            throw new InvalidArgumentException("Configuration [engine.config_bundle.writers] must be an array of class names implementing [{$contract}], got [{$type}].");
        }

        $writers = [];

        foreach ($configured as $entry) {
            if (!is_string($entry) || !is_a($entry, $contract, true)) {
                $name = is_string($entry) ? $entry : get_debug_type($entry);

                throw new InvalidArgumentException("Entry [{$name}] in [engine.config_bundle.writers] must be a class name implementing [{$contract}].");
            }

            $writers[] = $app->make($entry, ['targetKeys' => $targetKeys]);
        }

        return $writers;
    }

    protected function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', fn (?User $user): bool => !app()->isProduction());

        if (!class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()->withDocumentTransformers(ObjectTypeDocumentTransformer::class);
    }

    protected function configureObjectTypeRegistry(): void
    {
        $forget = function (?string $objectTypeId = null): void {
            app(ObjectTypeRegistry::class)->forget($objectTypeId);
            app(ObjectTypeBackingRegistry::class)->forget();
        };

        foreach (['saved', 'deleted', 'restored'] as $event) {
            ObjectType::{$event}(fn (ObjectType $type) => $forget((string) $type->getKey()));
            FieldDefinition::{$event}(fn (FieldDefinition $field) => $forget($field->object_type_id));
            RelationshipType::{$event}(function (RelationshipType $type) use ($forget): void {
                $forget($type->from_object_type_id);
                $forget($type->to_object_type_id);
            });
        }
    }

    protected function configureObservers(): void
    {
        Event::listen('eloquent.deleted: *', function (string $event, array $models): void {
            foreach ($models as $model) {
                if ($model instanceof Model && !$model instanceof CustomRecord) {
                    app(RecordLinkCascadeObserver::class)->deleted($model);
                }
            }
        });
    }

    protected function configureAuthorization(): void
    {
        Gate::policy(TypedRecord::class, CustomRecordPolicy::class);

        Gate::before(function (Authenticatable $user, string $ability, array $arguments = []): ?bool {
            if (!$user instanceof User) {
                return null;
            }

            if ($this->abilityIsPolicyBacked($ability, $arguments[0] ?? null)) {
                return null;
            }

            if ($user->isEscalatedAuthority() || $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });
    }

    protected function abilityIsPolicyBacked(string $ability, mixed $target): bool
    {
        if ($target === null) {
            return false;
        }

        $policy = Gate::getPolicyFor($target);

        if ($policy === null) {
            return false;
        }

        $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

        return is_callable([$policy, $method]) || method_exists($policy, 'before');
    }

    protected function configureTenantContextPropagation(): void
    {
        Context::hydrated(fn (ContextRepository $context) => $this->rebindTenantContext($context));
    }

    public function rebindTenantContext(ContextRepository $context): void
    {
        app()->forgetInstance('current_tenant');
        app()->forgetInstance('current_team');

        if ($context->hasHidden('team_id')) {
            $team = Team::withoutTenantScope()->find($context->getHidden('team_id'));

            if ($team !== null) {
                app()->instance('current_team', $team);
            }
        }

        if ($context->hasHidden('tenant_id')) {
            $tenant = Tenant::query()->find($context->getHidden('tenant_id'));

            if ($tenant !== null) {
                app()->instance('current_tenant', $tenant);
            }
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
