<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\DTOs\ConfigBundle\ConfigBundle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class BundleArchivePacker
{
    private string $fixedModificationTime = '2000-01-01T00:00:00+00:00';

    private CompressionMethod $compressionMethod = CompressionMethod::DEFLATE;

    private int $deflateLevel = 6;

    private string $manifestFile;

    public function __construct(private readonly ConfigBundleSerializer $serializer)
    {
        $this->manifestFile = (string) config('engine.config_bundle.manifest_file');
    }

    public function stream(ConfigBundle $bundle, string $fileName): StreamedResponse
    {
        return response()->streamDownload(
            fn () => $this->writeArchive($bundle),
            $fileName,
            ['Content-Type' => 'application/zip'],
        );
    }

    private function writeArchive(ConfigBundle $bundle): void
    {
        $directory = storage_path('app/private/tmp/config-export-'.Str::ulid());

        try {
            $this->serializer->writeTo($bundle, $directory);

            $zip = new ZipStream(
                defaultCompressionMethod: $this->compressionMethod,
                defaultDeflateLevel: $this->deflateLevel,
                sendHttpHeaders: false,
            );
            $modifiedAt = CarbonImmutable::parse($this->fixedModificationTime);

            foreach ($this->entryNames($directory) as $name) {
                $zip->addFileFromPath(
                    fileName: $name,
                    path: "{$directory}/{$name}",
                    lastModificationDateTime: $modifiedAt,
                );
            }

            $zip->finish();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function entryNames(string $directory): array
    {
        $names = array_map(
            static fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1)),
            File::allFiles($directory),
        );

        $artifactNames = array_values(array_diff($names, [$this->manifestFile]));
        sort($artifactNames);

        return [$this->manifestFile, ...$artifactNames];
    }
}
