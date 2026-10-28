<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Exceptions\ConfigBundle\UnsafeArchiveException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

class BundleArchiveExtractor
{
    public static int $maxEntries = 10000;

    public static int $maxTotalUncompressedBytes = 52428800;

    public static int $maxCompressionRatio = 200;

    public static int $compressionRatioFloorBytes = 1048576;

    public static int $maxDepth = 2;

    public static int $maxNameSegmentBytes = 255;

    private string $allowedExtension = 'yaml';

    private string $allowedEntryNamePattern = '#^[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)*$#';

    /**
     * @throws UnsafeArchiveException
     */
    public function extract(string $absoluteZipPath, string $targetDirectory): void
    {
        $root = realpath($targetDirectory);

        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException("The extraction target {$targetDirectory} is not an existing directory.");
        }

        $zip = new ZipArchive;

        if ($zip->open($absoluteZipPath, ZipArchive::CHECKCONS) !== true) {
            throw UnsafeArchiveException::unreadableContainer();
        }

        try {
            $entries = $this->preflight($zip, $root);
            $contents = $this->readContents($zip, $entries);
        } finally {
            $zip->close();
        }

        $this->write($root, $entries, $contents);
    }

    /**
     * @return array<string, array{index: int, directory: bool, size: int, crc: int}>
     */
    private function preflight(ZipArchive $zip, string $root): array
    {
        if ($zip->numFiles > self::$maxEntries) {
            throw UnsafeArchiveException::tooManyEntries($zip->numFiles, self::$maxEntries);
        }

        $entries = [];
        $seenKeys = [];
        $parentDirectories = [];
        $totalBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw UnsafeArchiveException::unreadableContainer();
            }

            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;

            $this->assertSafePath($name, $path, $root);

            if (count(explode('/', $path)) > self::$maxDepth) {
                throw UnsafeArchiveException::tooDeep($name, self::$maxDepth);
            }

            if (!$directory && !str_ends_with($path, '.'.$this->allowedExtension)) {
                throw UnsafeArchiveException::disallowedExtension($name);
            }

            $key = Str::lower($path);

            if (isset($seenKeys[$key])) {
                throw UnsafeArchiveException::duplicateEntry($name);
            }

            $size = $stat['size'];
            $compressedSize = $stat['comp_size'];
            $totalBytes += $size;

            if ($totalBytes > self::$maxTotalUncompressedBytes) {
                throw UnsafeArchiveException::totalSizeExceeded(self::$maxTotalUncompressedBytes);
            }

            if ($size >= self::$compressionRatioFloorBytes && $compressedSize > 0 && $size / $compressedSize > self::$maxCompressionRatio) {
                throw UnsafeArchiveException::compressionRatioExceeded($name);
            }

            $entries[$path] = ['index' => $index, 'directory' => $directory, 'size' => $size, 'crc' => $stat['crc']];
            $seenKeys[$key] = true;

            for ($parent = dirname($key); $parent !== '.'; $parent = dirname($parent)) {
                $parentDirectories[$parent] = true;
            }
        }

        foreach ($entries as $path => $entry) {
            if (!$entry['directory'] && isset($parentDirectories[Str::lower($path)])) {
                throw UnsafeArchiveException::fileAndDirectory($path);
            }
        }

        return $entries;
    }

    private function assertSafePath(string $name, string $path, string $root): void
    {
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            throw UnsafeArchiveException::disallowedName($name);
        }

        if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1 || preg_match($this->allowedEntryNamePattern, $path) !== 1) {
            throw UnsafeArchiveException::disallowedName($name);
        }

        foreach (explode('/', $path) as $segment) {
            if (strlen($segment) > self::$maxNameSegmentBytes) {
                throw UnsafeArchiveException::nameTooLong($name, self::$maxNameSegmentBytes);
            }
        }

        $existing = $root.'/'.$path;

        if (strlen($existing) >= PHP_MAXPATHLEN - 1) {
            throw UnsafeArchiveException::pathTooLong($name);
        }

        while (!file_exists($existing) && !is_link($existing)) {
            $existing = dirname($existing);
        }

        $resolved = realpath($existing);

        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root.'/'))) {
            throw UnsafeArchiveException::disallowedName($name);
        }
    }

    /**
     * @param  array<string, array{index: int, directory: bool, size: int, crc: int}>  $entries
     * @return array<string, string>
     */
    private function readContents(ZipArchive $zip, array $entries): array
    {
        $contents = [];

        foreach ($entries as $path => $entry) {
            if ($entry['directory']) {
                continue;
            }

            $stream = $zip->getStreamIndex($entry['index']);

            if (!is_resource($stream)) {
                throw UnsafeArchiveException::unreadableContainer();
            }

            try {
                $data = stream_get_contents($stream, $entry['size'] + 1);
            } finally {
                fclose($stream);
            }

            if (!is_string($data) || strlen($data) !== $entry['size'] || crc32($data) !== $entry['crc']) {
                throw UnsafeArchiveException::declaredSizeMismatch($path);
            }

            $contents[$path] = $data;
        }

        return $contents;
    }

    /**
     * @param  array<string, array{index: int, directory: bool, size: int, crc: int}>  $entries
     * @param  array<string, string>  $contents
     */
    private function write(string $root, array $entries, array $contents): void
    {
        foreach ($entries as $path => $entry) {
            $target = $root.'/'.$path;

            if ($entry['directory']) {
                File::ensureDirectoryExists($target);

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            File::put($target, $contents[$path]);
        }
    }
}
