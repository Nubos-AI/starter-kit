<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Support\ConfigBundle\SchemaVersion\BundleSchemaMigratorRegistry;
use Illuminate\Support\Facades\File;

class ConfigBundleReader
{
    private string $manifestFile;

    /**
     * @var list<string>
     */
    private array $manifestKeys = ['schema_version', 'source_label', 'artifact_counts', 'warnings'];

    public function __construct(
        private readonly YamlCodec $codec,
        private readonly BundleSchemaMigratorRegistry $migrators,
    ) {
        $this->manifestFile = (string) config('engine.config_bundle.manifest_file');
    }

    /**
     * @throws MalformedBundleException
     */
    public function readFrom(string $directory): ConfigBundle
    {
        $tree = $this->tree($directory);

        if (!array_key_exists($this->manifestFile, $tree)) {
            throw MalformedBundleException::missingManifest($this->manifestFile);
        }

        $tree = $this->migrators->upgrade($tree, (int) ($tree[$this->manifestFile]['schema_version'] ?? 0));

        $this->assertManifestKeys($tree[$this->manifestFile]);

        $tree[$this->manifestFile]['schema_version'] = $this->migrators->currentVersion();

        $artifacts = [];

        foreach ($tree as $relative => $payload) {
            if ($relative === $this->manifestFile) {
                continue;
            }

            $artifacts[] = new BundleArtifact(
                ArtifactKind::from(explode('/', $relative)[0]),
                $this->businessKeyOf($relative, $payload),
                $payload,
            );
        }

        return new ConfigBundle(BundleManifest::fromArray($tree[$this->manifestFile]), $artifacts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tree(string $directory): array
    {
        if (!File::isDirectory($directory)) {
            throw MalformedBundleException::missingManifest($this->manifestFile);
        }

        $relatives = [];

        foreach (File::allFiles($directory) as $file) {
            $relatives[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        }

        sort($relatives);

        $tree = [];

        foreach ($relatives as $relative) {
            $this->assertEntry($relative);

            $tree[$relative] = $this->codec->parse(File::get($directory.'/'.$relative), $relative);
        }

        return $tree;
    }

    private function assertEntry(string $relative): void
    {
        if ($relative === $this->manifestFile) {
            return;
        }

        $segments = explode('/', $relative);

        if (count($segments) !== 2 || !str_ends_with($segments[1], '.yaml') || ArtifactKind::tryFrom($segments[0]) === null) {
            throw MalformedBundleException::unexpectedEntry($relative);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestKeys(array $manifest): void
    {
        $present = array_keys($manifest);
        $unknown = array_values(array_diff($present, $this->manifestKeys));
        $missing = array_values(array_diff($this->manifestKeys, $present));

        if ($unknown !== [] || $missing !== []) {
            throw MalformedBundleException::manifestKeySetMismatch($this->manifestFile, $unknown, $missing);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function businessKeyOf(string $relative, array $payload): string
    {
        $key = $payload['key'] ?? null;

        if (!is_string($key) || $key === '') {
            throw MalformedBundleException::missingBusinessKey($relative);
        }

        return $key;
    }
}
