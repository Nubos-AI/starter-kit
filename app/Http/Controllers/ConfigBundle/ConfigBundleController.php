<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConfigBundle;

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Actions\ConfigBundle\PrepareConfigImportAction;
use App\Actions\ConfigBundle\ResolveImportPlaceholdersAction;
use App\DTOs\ConfigBundle\PlaceholderRequirement;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Enums\Promotion\PromotionDirection;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UnsafeArchiveException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\ConfigBundle\BundleArchivePacker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConfigBundleController extends Controller
{
    /**
     * @var list<string>
     */
    private array $allowedExtensions = ['zip'];

    private int $maxUploadKilobytes = 20480;

    public function __construct(
        private readonly ExportConfigBundleAction $exportConfigBundle,
        private readonly PrepareConfigImportAction $prepareConfigImport,
        private readonly ResolveImportPlaceholdersAction $resolveImportPlaceholders,
        private readonly BundleArchivePacker $packer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        if (!$user->hasPermission('config.export') && !$user->hasPermission('config.import')) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.config_bundle.config_bundle_controller.you_do_not_have_permission_to_use_configuration_as'));
        }

        $exportRefusal = $this->exportConfigBundle->refusalFor($user);
        $importRefusal = $this->prepareConfigImport->refusalFor($user);

        return Inertia::render('config/Index', [
            'can_export' => $exportRefusal === null,
            'export_reason' => $exportRefusal,
            'can_import' => $importRefusal === null,
            'import_reason' => $importRefusal,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $this->authorizePermission($request, 'config.export');

        $bundle = $this->refusingOn('export', fn () => $this->exportConfigBundle->execute($user, ConfigExportEntryPoint::Download));
        $slug = Str::slug($bundle->manifest->sourceLabel) ?: 'mandant';
        $date = now('UTC')->format('Y-m-d');

        return $this->packer->stream($bundle, "konfiguration-{$slug}-{$date}.zip");
    }

    public function upload(Request $request): RedirectResponse
    {
        $user = $this->authorizePermission($request, 'config.import');

        $request->validate(
            ['file' => ['required', 'file', "max:{$this->maxUploadKilobytes}"]],
            [],
            ['file' => __('i18n.backend.http.controllers.config_bundle.config_bundle_controller.archive')],
        );

        $file = $request->file('file');

        if (!$file instanceof UploadedFile || !in_array(mb_strtolower($file->getClientOriginalExtension()), $this->allowedExtensions, true)) {
            throw ValidationException::withMessages(['file' => __('i18n.backend.http.controllers.config_bundle.config_bundle_controller.please_upload_a_zip_archive')]);
        }

        $run = $this->refusingOn('file', fn () => $this->prepareConfigImport->execute($user, (string) $file->getRealPath()));

        if ($this->resolveImportPlaceholders->requirementsFor($run) === []) {
            return to_route('engine.promotions.show', ['promotionRun' => $run]);
        }

        return to_route('engine.config.import.placeholders', ['run' => $run->getKey()]);
    }

    public function placeholders(Request $request, string $run): Response
    {
        $user = $this->authorizePermission($request, 'config.import');

        $importRun = $this->bundleImportRun($user, $run);
        $refusal = $this->resolveImportPlaceholders->refusalFor($user, $importRun);

        try {
            $requirements = $this->resolveImportPlaceholders->requirementsFor($importRun);
        } catch (PromotionSourceUnavailableException|MalformedBundleException|UnsupportedBundleSchemaVersionException $exception) {
            $requirements = [];
            $refusal = $exception->getMessage();
        }

        return Inertia::render('config/Placeholders', [
            'run' => ['id' => (string) $importRun->getKey()],
            'requirements' => array_map(
                static fn (PlaceholderRequirement $requirement): array => $requirement->toArray(),
                $requirements,
            ),
            'can_save' => $refusal === null,
            'save_reason' => $refusal,
        ]);
    }

    public function storePlaceholders(Request $request, string $run): RedirectResponse
    {
        $user = $this->authorizePermission($request, 'config.import');

        $importRun = $this->bundleImportRun($user, $run);

        $this->refusingOn('targets', fn () => $this->resolveImportPlaceholders->execute($user, $importRun, $request->all()));

        return to_route('engine.promotions.show', ['promotionRun' => $importRun]);
    }

    private function bundleImportRun(User $user, string $run): PromotionRun
    {
        return PromotionRun::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('direction', PromotionDirection::BundleImport)
            ->whereKey($run)
            ->firstOrFail();
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     *
     * @throws ValidationException
     */
    private function refusingOn(string $field, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UnsafeArchiveException|UnsupportedBundleSchemaVersionException|MalformedBundleException|PromotionSourceUnavailableException|AmbiguousArtifactKeyException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
