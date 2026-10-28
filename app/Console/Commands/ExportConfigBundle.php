<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigBundle\ExportConfigBundleAction;
use App\Enums\ConfigBundle\ConfigExportEntryPoint;
use App\Models\User;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Console\ActingUserResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExportConfigBundle extends Command
{
    protected $signature = 'config:export {--as-user= : E-mail address or ULID of the acting user (required)} {--path= : Target directory (required)}';

    protected $description = 'Export the configuration bundle of the acting user\'s tenant into a directory';

    public function handle(
        ActingUserResolver $resolver,
        ActingUserContext $actingUserContext,
        ConfigBundleSerializer $serializer,
        ExportConfigBundleAction $exportConfigBundle,
    ): int {
        $actingUserId = null;
        $directory = $this->textOption('path');
        $fileCount = 0;

        try {
            $actor = $resolver->resolve($this->textOption('as-user'));
            $actingUserId = (string) $actor->getKey();

            $actingUserContext->run(
                (string) $actor->tenant_id,
                $actingUserId,
                function (User $boundUser) use ($serializer, $exportConfigBundle, $directory, &$fileCount): void {
                    $refusal = $exportConfigBundle->refusalFor($boundUser);

                    if ($refusal !== null) {
                        throw new AuthorizationException($refusal);
                    }

                    $target = $this->usableTarget($directory);
                    $bundle = $exportConfigBundle->execute($boundUser, ConfigExportEntryPoint::Console);

                    $serializer->writeTo($bundle, $target);

                    $fileCount = count(File::allFiles($target));
                },
            );
        } catch (Throwable $exception) {
            Log::error('Config export command failed.', [
                'acting_user_id' => $actingUserId,
                'exception_class' => $exception::class,
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$fileCount} Dateien wurden nach {$directory} exportiert.");

        return self::SUCCESS;
    }

    private function textOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @throws ValidationException
     */
    private function usableTarget(?string $directory): string
    {
        if ($directory === null || $directory === '') {
            throw ValidationException::withMessages(['path' => 'Die Option --path ist erforderlich.']);
        }

        if (File::isFile($directory) || (File::isDirectory($directory) && !File::isEmptyDirectory($directory))) {
            throw ValidationException::withMessages([
                'path' => 'Das Zielverzeichnis unter --path muss leer sein oder darf noch nicht existieren.',
            ]);
        }

        return $directory;
    }
}
