<?php

declare(strict_types=1);

use App\Exceptions\ConfigBundle\UnsafeArchiveException;
use App\Support\ConfigBundle\BundleArchiveExtractor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->originalLimits = [
        'maxEntries' => BundleArchiveExtractor::$maxEntries,
        'maxTotalUncompressedBytes' => BundleArchiveExtractor::$maxTotalUncompressedBytes,
        'maxCompressionRatio' => BundleArchiveExtractor::$maxCompressionRatio,
        'compressionRatioFloorBytes' => BundleArchiveExtractor::$compressionRatioFloorBytes,
        'maxDepth' => BundleArchiveExtractor::$maxDepth,
    ];

    $this->root = sys_get_temp_dir().'/nubos-bundle-extractor-'.bin2hex(random_bytes(6));
    $this->target = $this->root.'/target';

    File::ensureDirectoryExists($this->target);

    $this->extractor = static fn (): BundleArchiveExtractor => app(BundleArchiveExtractor::class);

    $this->manifestYaml = "schema_version: 2\nsource_label: 'Acme Sales'\n";

    /** @var callable(array<string, string>, string):string */
    $this->buildZip = function (array $entries, string $name = 'bundle.zip'): string {
        $path = $this->root.'/'.$name;
        $zip = new ZipArchive;

        expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

        foreach ($entries as $entryName => $content) {
            if (str_ends_with($entryName, '/')) {
                expect($zip->addEmptyDir(rtrim($entryName, '/')))->toBeTrue();

                continue;
            }

            expect($zip->addFromString($entryName, $content))->toBeTrue();
        }

        expect($zip->close())->toBeTrue();

        return $path;
    };

    /** @var callable(string, string, string):void */
    $this->patchEntryName = static function (string $path, string $from, string $to): void {
        expect(strlen($from))->toBe(strlen($to));

        $bytes = (string) file_get_contents($path);
        $patched = 0;
        $offset = 0;

        while (($position = strpos($bytes, "PK\x03\x04", $offset)) !== false) {
            $nameLength = unpack('v', substr($bytes, $position + 26, 2))[1];

            if (substr($bytes, $position + 30, $nameLength) === $from) {
                $bytes = substr_replace($bytes, $to, $position + 30, $nameLength);
                $patched++;
            }

            $offset = $position + 4;
        }

        $offset = 0;

        while (($position = strpos($bytes, "PK\x01\x02", $offset)) !== false) {
            $nameLength = unpack('v', substr($bytes, $position + 28, 2))[1];

            if (substr($bytes, $position + 46, $nameLength) === $from) {
                $bytes = substr_replace($bytes, $to, $position + 46, $nameLength);
                $patched++;
            }

            $offset = $position + 4;
        }

        expect($patched)->toBe(2);

        file_put_contents($path, $bytes);
    };

    /** @var callable(string, string, int):void */
    $this->lieAboutDeclaredSize = static function (string $path, string $entryName, int $declaredSize): void {
        $bytes = (string) file_get_contents($path);
        $patched = 0;
        $offset = 0;

        while (($position = strpos($bytes, "PK\x03\x04", $offset)) !== false) {
            $nameLength = unpack('v', substr($bytes, $position + 26, 2))[1];

            if (substr($bytes, $position + 30, $nameLength) === $entryName) {
                $bytes = substr_replace($bytes, pack('V', $declaredSize), $position + 22, 4);
                $patched++;
            }

            $offset = $position + 4;
        }

        $offset = 0;

        while (($position = strpos($bytes, "PK\x01\x02", $offset)) !== false) {
            $nameLength = unpack('v', substr($bytes, $position + 28, 2))[1];

            if (substr($bytes, $position + 46, $nameLength) === $entryName) {
                $bytes = substr_replace($bytes, pack('V', $declaredSize), $position + 24, 4);
                $patched++;
            }

            $offset = $position + 4;
        }

        expect($patched)->toBe(2);

        file_put_contents($path, $bytes);
    };

    /** @var callable(int):string */
    $this->incompressible = static function (int $bytes): string {
        $buffer = '';
        $counter = 0;

        while (strlen($buffer) < $bytes) {
            $buffer .= hash('sha256', "nubos-bundle-extractor-{$counter}", true);
            $counter++;
        }

        return substr($buffer, 0, $bytes);
    };

    /** @var callable(string, string):array{size: int, comp_size: int} */
    $this->statOf = static function (string $path, string $entryName): array {
        $zip = new ZipArchive;

        expect($zip->open($path))->toBeTrue();

        $stat = $zip->statName($entryName);
        $zip->close();

        expect($stat)->toBeArray();

        return ['size' => (int) $stat['size'], 'comp_size' => (int) $stat['comp_size']];
    };

    /** @var callable(string):array<string, string> */
    $this->filesUnder = static function (string $directory): array {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    };

    /** @var callable(int):string */
    $this->entryNameReachingAbsoluteLength = function (int $absoluteLength): string {
        $remaining = $absoluteLength - strlen((string) realpath($this->target)) - 1;
        $segments = [];

        while ($remaining > BundleArchiveExtractor::$maxNameSegmentBytes + 1) {
            $segments[] = str_repeat('p', BundleArchiveExtractor::$maxNameSegmentBytes);
            $remaining -= BundleArchiveExtractor::$maxNameSegmentBytes + 1;
        }

        expect($remaining)->toBeGreaterThan(strlen('.yaml'));

        $segments[] = str_repeat('f', $remaining - strlen('.yaml')).'.yaml';

        return implode('/', $segments);
    };

    /** @var callable(string):void */
    $this->rejectsWithoutWriting = function (string $zipPath): void {
        $thrown = null;

        try {
            ($this->extractor)()->extract($zipPath, $this->target);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(UnsafeArchiveException::class);

        $message = $thrown->getMessage();

        expect($message)->not->toBe('')
            ->and($message)->not->toMatch('/\b(the|entry|entries|is|too|many|allowed|invalid|exceeds|contains|unsafe)\b/i')
            ->and($message)->toMatch('/\b(der|die|das|des|den|dem|ein|eine|einen|ist|sind|nicht|enthält|Archiv|Eintrag|Einträge|Datei|zu|mehr)\b/u');

        $leftoversInTarget = array_values(array_diff((array) scandir($this->target), ['.', '..']));

        $leftoversBesideTheArchives = array_values(array_filter(
            array_keys(($this->filesUnder)($this->root)),
            static fn (string $relative): bool => !str_ends_with($relative, '.zip'),
        ));

        expect($leftoversInTarget)->toBe([])
            ->and($leftoversBesideTheArchives)->toBe([]);
    };
});

afterEach(function (): void {
    BundleArchiveExtractor::$maxEntries = $this->originalLimits['maxEntries'];
    BundleArchiveExtractor::$maxTotalUncompressedBytes = $this->originalLimits['maxTotalUncompressedBytes'];
    BundleArchiveExtractor::$maxCompressionRatio = $this->originalLimits['maxCompressionRatio'];
    BundleArchiveExtractor::$compressionRatioFloorBytes = $this->originalLimits['compressionRatioFloorBytes'];
    BundleArchiveExtractor::$maxDepth = $this->originalLimits['maxDepth'];

    File::deleteDirectory($this->root);
});

it('extracts a clean bundle archive byte for byte into the target directory', function (): void {
    $entries = [
        'manifest.yaml' => $this->manifestYaml,
        'roles/' => '',
        'roles/sales-manager.yaml' => "key: 'Sales Manager'\nname: 'Sales Manager'\n",
        'object-types/deal.yaml' => "key: deal\nname: Deal\n",
    ];

    ($this->extractor)()->extract(($this->buildZip)($entries), $this->target);

    expect(($this->filesUnder)($this->target))->toBe([
        'manifest.yaml' => $this->manifestYaml,
        'object-types/deal.yaml' => "key: deal\nname: Deal\n",
        'roles/sales-manager.yaml' => "key: 'Sales Manager'\nname: 'Sales Manager'\n",
    ])
        ->and(is_dir($this->target.'/roles'))->toBeTrue();
});

it('rejects a parent traversal entry and writes nothing, neither inside the target nor beside it', function (): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        '../evil.yaml' => "key: evil\n",
    ]);

    ($this->rejectsWithoutWriting)($path);

    expect(file_exists($this->root.'/evil.yaml'))->toBeFalse()
        ->and(file_exists($this->target.'/evil.yaml'))->toBeFalse();
});

it('rejects an entry that resolves through a symlink inside the target to a place outside of it and writes nothing', function (string $linkName, string $linkTarget, string $entryName): void {
    $outside = $this->root.'/outside';
    File::ensureDirectoryExists($outside);

    expect(symlink($this->root.'/'.$linkTarget, $this->target.'/'.$linkName))->toBeTrue();

    $thrown = null;

    try {
        ($this->extractor)()->extract(($this->buildZip)([
            'manifest.yaml' => $this->manifestYaml,
            $entryName => "key: evil\n",
        ]), $this->target);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UnsafeArchiveException::class)
        ->and($thrown->getMessage())->toContain($entryName)
        ->and(array_values(array_diff((array) scandir($this->target), ['.', '..'])))->toBe([$linkName])
        ->and(array_values(array_diff((array) scandir($outside), ['.', '..'])))->toBe([])
        ->and(file_exists($this->root.'/evil.yaml'))->toBeFalse();
})->with([
    'a symlinked directory' => ['roles', 'outside', 'roles/evil.yaml'],
    'a symlinked file' => ['evil.yaml', 'outside/evil.yaml', 'evil.yaml'],
]);

it('rejects an unsafe entry name before anything is written', function (string $unsafeName): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $unsafeName => "key: evil\n",
    ]);

    ($this->rejectsWithoutWriting)($path);

    expect(file_exists($this->root.'/evil.yaml'))->toBeFalse();
})->with([
    'leading slash' => ['/evil.yaml'],
    'backslash traversal' => ['..\\evil.yaml'],
    'backslash as separator' => ['roles\\evil.yaml'],
    'drive letter' => ['C:/evil.yaml'],
    'current directory segment' => ['roles/./evil.yaml'],
    'nested parent segments' => ['roles/../../evil.yaml'],
    'character outside the allowed pattern' => ['roles/ev$il.yaml'],
]);

it('rejects an entry whose name carries a null byte before anything is written', function (): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        'aXb.yaml' => "key: evil\n",
    ]);

    ($this->patchEntryName)($path, 'aXb.yaml', "a\0b.yaml");

    ($this->rejectsWithoutWriting)($path);
});

it('rejects an entry that is not a yaml file before anything is written', function (string $foreignName): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $foreignName => "key: foreign\n",
    ]);

    ($this->rejectsWithoutWriting)($path);
})->with([
    'json file' => ['roles/sales-manager.json'],
    'php behind a yaml suffix' => ['roles/sales-manager.yaml.php'],
    'short yml suffix' => ['roles/sales-manager.yml'],
    'file without extension' => ['roles/sales-manager'],
]);

it('accepts an entry exactly at the depth limit', function (): void {
    $name = implode('/', array_fill(0, BundleArchiveExtractor::$maxDepth - 1, 'roles')).'/sales-manager.yaml';

    ($this->extractor)()->extract(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: 'Sales Manager'\n",
    ]), $this->target);

    expect(($this->filesUnder)($this->target))->toBe([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: 'Sales Manager'\n",
    ]);
});

it('rejects an entry nested one level deeper than the depth limit before anything is written', function (): void {
    $name = implode('/', array_fill(0, BundleArchiveExtractor::$maxDepth, 'roles')).'/sales-manager.yaml';

    ($this->rejectsWithoutWriting)(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: 'Sales Manager'\n",
    ]));
});

it('rejects two entries carrying the same name before anything is written', function (): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        'roles/a.yaml' => "key: first\n",
        'roles/b.yaml' => "key: second\n",
    ]);

    ($this->patchEntryName)($path, 'roles/b.yaml', 'roles/a.yaml');

    ($this->rejectsWithoutWriting)($path);
});

it('rejects two entries whose names differ only in letter case as duplicates before anything is written', function (array $entries, string $duplicateEntry): void {
    $path = ($this->buildZip)(['manifest.yaml' => $this->manifestYaml, ...$entries]);

    ($this->rejectsWithoutWriting)($path);

    expect(fn () => ($this->extractor)()->extract($path, $this->target))
        ->toThrow(UnsafeArchiveException::class, UnsafeArchiveException::duplicateEntry($duplicateEntry)->getMessage());
})->with([
    'two nested files' => [['roles/A.yaml' => "key: upper\n", 'roles/a.yaml' => "key: lower\n"], 'roles/a.yaml'],
    'a directory entry and a file' => [['A.yaml/' => '', 'a.yaml' => "key: a\n"], 'a.yaml'],
]);

it('rejects a file entry that is also the parent directory of another entry before anything is written', function (array $entries, string $fileEntry, int $maxDepth): void {
    BundleArchiveExtractor::$maxDepth = $maxDepth;

    $path = ($this->buildZip)(['manifest.yaml' => $this->manifestYaml, ...$entries]);

    ($this->rejectsWithoutWriting)($path);

    expect(fn () => ($this->extractor)()->extract($path, $this->target))
        ->toThrow(UnsafeArchiveException::class, UnsafeArchiveException::fileAndDirectory($fileEntry)->getMessage());
})->with([
    'the file entry first' => [['a.yaml' => "key: a\n", 'a.yaml/b.yaml' => "key: b\n"], 'a.yaml', 2],
    'the directory content first' => [['a.yaml/b.yaml' => "key: b\n", 'a.yaml' => "key: a\n"], 'a.yaml', 2],
    'a deeply nested entry below the file' => [['a.yaml' => "key: a\n", 'a.yaml/x/y.yaml' => "key: y\n"], 'a.yaml', 3],
    'a nested file shadowing a nested directory' => [['roles/x/y.yaml' => "key: y\n", 'roles/x.yaml/z.yaml' => "key: z\n", 'roles/x.yaml' => "key: x\n"], 'roles/x.yaml', 3],
    'the file entry first in another letter case' => [['A.yaml' => "key: a\n", 'a.yaml/b.yaml' => "key: b\n"], 'A.yaml', 2],
    'the directory content first in another letter case' => [['a.yaml/b.yaml' => "key: b\n", 'A.yaml' => "key: a\n"], 'A.yaml', 2],
]);

it('accepts name segments exactly at the segment byte limit', function (): void {
    $limit = BundleArchiveExtractor::$maxNameSegmentBytes;
    $fileName = 'roles/'.str_repeat('d', $limit - strlen('.yaml')).'.yaml';
    $nestedName = str_repeat('b', $limit).'/x.yaml';

    expect(strlen(basename($fileName)))->toBe($limit)
        ->and(strlen(dirname($nestedName)))->toBe($limit);

    ($this->extractor)()->extract(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $fileName => "key: file\n",
        $nestedName => "key: nested\n",
    ]), $this->target);

    expect(($this->filesUnder)($this->target))->toBe([
        $nestedName => "key: nested\n",
        'manifest.yaml' => $this->manifestYaml,
        $fileName => "key: file\n",
    ]);
});

it('rejects a name segment one byte longer than the segment byte limit before anything is written', function (string $overlongName): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $overlongName => "key: overlong\n",
    ]);

    ($this->rejectsWithoutWriting)($path);

    expect(fn () => ($this->extractor)()->extract($path, $this->target))
        ->toThrow(UnsafeArchiveException::class, UnsafeArchiveException::nameTooLong($overlongName, BundleArchiveExtractor::$maxNameSegmentBytes)->getMessage());
})->with([
    'a file segment' => ['roles/'.str_repeat('d', 251).'.yaml'],
    'a directory segment' => [str_repeat('b', 256).'/x.yaml'],
]);

it('accepts an entry whose absolute target path is as long as the platform path limit allows', function (): void {
    BundleArchiveExtractor::$maxDepth = 64;

    $name = ($this->entryNameReachingAbsoluteLength)(PHP_MAXPATHLEN - 2);

    ($this->extractor)()->extract(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: deep\n",
    ]), $this->target);

    expect(($this->filesUnder)($this->target))->toBe([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: deep\n",
    ]);
});

it('rejects an entry whose absolute target path exceeds the platform path limit before anything is written', function (): void {
    BundleArchiveExtractor::$maxDepth = 64;

    $name = ($this->entryNameReachingAbsoluteLength)(PHP_MAXPATHLEN - 1);
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        $name => "key: deep\n",
    ]);

    ($this->rejectsWithoutWriting)($path);

    expect(fn () => ($this->extractor)()->extract($path, $this->target))
        ->toThrow(UnsafeArchiveException::class, UnsafeArchiveException::pathTooLong($name)->getMessage());
});

it('accepts an archive with exactly as many entries as the entry limit allows', function (): void {
    BundleArchiveExtractor::$maxEntries = 3;

    ($this->extractor)()->extract(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        'roles/a.yaml' => "key: a\n",
        'roles/b.yaml' => "key: b\n",
    ]), $this->target);

    expect(array_keys(($this->filesUnder)($this->target)))->toBe(['manifest.yaml', 'roles/a.yaml', 'roles/b.yaml']);
});

it('rejects an archive with one entry more than the entry limit before anything is written', function (): void {
    BundleArchiveExtractor::$maxEntries = 3;

    ($this->rejectsWithoutWriting)(($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        'roles/a.yaml' => "key: a\n",
        'roles/b.yaml' => "key: b\n",
        'roles/c.yaml' => "key: c\n",
    ]));
});

it('accepts an archive whose uncompressed total stays below the size limit', function (): void {
    BundleArchiveExtractor::$maxTotalUncompressedBytes = 8192;

    $first = ($this->incompressible)(1500);
    $second = strrev(($this->incompressible)(1500));

    ($this->extractor)()->extract(($this->buildZip)([
        'roles/a.yaml' => $first,
        'roles/b.yaml' => $second,
    ]), $this->target);

    expect(($this->filesUnder)($this->target))->toBe([
        'roles/a.yaml' => $first,
        'roles/b.yaml' => $second,
    ]);
});

it('rejects an archive whose uncompressed total exceeds the size limit before anything is written', function (): void {
    BundleArchiveExtractor::$maxTotalUncompressedBytes = 4000;

    ($this->rejectsWithoutWriting)(($this->buildZip)([
        'roles/a.yaml' => ($this->incompressible)(1500),
        'roles/b.yaml' => strrev(($this->incompressible)(1500)),
        'roles/c.yaml' => str_rot13(($this->incompressible)(1500)),
    ]));
});

it('rejects an entry whose compression ratio exceeds the ratio limit before anything is written', function (): void {
    $size = max(BundleArchiveExtractor::$compressionRatioFloorBytes, 1_048_576);
    $path = ($this->buildZip)(['roles/bomb.yaml' => str_repeat('A', $size)]);
    $stat = ($this->statOf)($path, 'roles/bomb.yaml');

    expect($stat['comp_size'])->toBeGreaterThan(0)
        ->and($stat['size'] / $stat['comp_size'])->toBeGreaterThan((float) BundleArchiveExtractor::$maxCompressionRatio)
        ->and($stat['size'])->toBeLessThanOrEqual(BundleArchiveExtractor::$maxTotalUncompressedBytes);

    ($this->rejectsWithoutWriting)($path);
});

it('extracts a large entry whose compression ratio stays within the ratio limit', function (): void {
    $size = BundleArchiveExtractor::$compressionRatioFloorBytes + 4096;
    $content = ($this->incompressible)($size);
    $path = ($this->buildZip)(['roles/large.yaml' => $content]);
    $stat = ($this->statOf)($path, 'roles/large.yaml');

    expect($stat['comp_size'])->toBeGreaterThan(0)
        ->and($stat['size'] / $stat['comp_size'])->toBeLessThanOrEqual((float) BundleArchiveExtractor::$maxCompressionRatio)
        ->and($size)->toBeLessThanOrEqual(BundleArchiveExtractor::$maxTotalUncompressedBytes);

    ($this->extractor)()->extract($path, $this->target);

    expect(($this->filesUnder)($this->target))->toBe(['roles/large.yaml' => $content]);
});

it('rejects an entry whose declared size understates its real content before anything is written', function (): void {
    $path = ($this->buildZip)([
        'manifest.yaml' => $this->manifestYaml,
        'roles/large.yaml' => str_repeat("key: value\n", 20000),
    ]);

    ($this->lieAboutDeclaredSize)($path, 'roles/large.yaml', 10);

    ($this->rejectsWithoutWriting)($path);
});

it('rejects a file that is not a zip container before anything is written', function (): void {
    $path = $this->root.'/bundle.zip';

    file_put_contents($path, "Dies ist kein Archiv.\n");

    ($this->rejectsWithoutWriting)($path);
});
