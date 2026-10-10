<?php

declare(strict_types=1);

namespace App\Support\Conventions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CommentScanner
{
    /** @var list<string> */
    private array $frontendExtensions = ['ts', 'js', 'vue'];

    /** @var list<string> */
    private array $frontendExcludedPaths = ['/components/ui/', '/js/actions/', '/js/routes/', '/js/wayfinder'];

    /**
     * @return list<string>
     */
    public function scanPhp(string $directory): array
    {
        $offenders = [];

        foreach ($this->files($directory, ['php']) as $file) {
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));

            foreach ($tokens as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $lead = $this->leadingContent(preg_split('/\R/', $token[1]) ?: []);

                if ($lead !== null) {
                    $offenders[] = $this->offender($file, $token[2], $lead);
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * @return list<string>
     */
    public function scanFrontend(string $directory): array
    {
        $offenders = [];

        foreach ($this->files($directory, $this->frontendExtensions) as $file) {
            $offenders = [...$offenders, ...$this->frontendOffenders($file)];
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * @return list<string>
     */
    private function frontendOffenders(SplFileInfo $file): array
    {
        $offenders = [];
        $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname())) ?: [];
        $insideStyle = false;
        $block = null;
        $blockLine = 0;

        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '<style')) {
                $insideStyle = true;
            }

            if (str_starts_with($trimmed, '</style')) {
                $insideStyle = false;

                continue;
            }

            if ($insideStyle) {
                continue;
            }

            if ($block !== null) {
                $block[] = $trimmed;

                if (str_ends_with($trimmed, '*/')) {
                    $lead = $this->leadingContent($block);

                    if ($lead !== null) {
                        $offenders[] = $this->offender($file, $blockLine, $lead);
                    }

                    $block = null;
                }

                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                $block = [$trimmed];
                $blockLine = $number;

                if (str_ends_with($trimmed, '*/')) {
                    $lead = $this->leadingContent($block);

                    if ($lead !== null) {
                        $offenders[] = $this->offender($file, $number, $lead);
                    }

                    $block = null;
                }

                continue;
            }

            $lead = $this->lineComment($line);

            if ($lead !== null) {
                $offenders[] = $this->offender($file, $number, $lead);
            }
        }

        return $offenders;
    }

    private function lineComment(string $line): ?string
    {
        $masked = str_replace('\\/', '\\x', $line);
        $position = 0;

        while (($position = strpos($masked, '//', $position)) !== false) {
            if ($this->outsideString(substr($masked, 0, $position))) {
                return $this->prose(substr($line, $position + 2));
            }

            $position += 2;
        }

        return null;
    }

    private function outsideString(string $prefix): bool
    {
        return substr_count($prefix, "'") % 2 === 0
            && substr_count($prefix, '"') % 2 === 0
            && substr_count($prefix, '`') % 2 === 0;
    }

    /**
     * @param  list<string>  $lines
     */
    private function leadingContent(array $lines): ?string
    {
        foreach ($lines as $line) {
            $stripped = trim((string) preg_replace('#^(/\*\*|/\*|\*/|\*|//|\#)#', '', trim($line)));
            $stripped = trim((string) preg_replace('#\*/$#', '', $stripped));

            if ($stripped === '') {
                continue;
            }

            return $this->prose($stripped);
        }

        return null;
    }

    private function prose(string $candidate): ?string
    {
        $content = trim((string) preg_replace('#\*/$#', '', trim($candidate)));

        if ($content === '' || str_starts_with($content, '@') || str_starts_with($content, 'eslint')) {
            return null;
        }

        return $content;
    }

    private function offender(SplFileInfo $file, int $line, string $content): string
    {
        return $file->getPathname().':'.$line.'  '.mb_substr($content, 0, 80);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<SplFileInfo>
     */
    private function files(string $directory, array $extensions): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (!in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            if ($this->isExcluded($file->getPathname(), $extensions)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @param  list<string>  $extensions
     */
    private function isExcluded(string $path, array $extensions): bool
    {
        if ($extensions === ['php']) {
            return false;
        }

        foreach ($this->frontendExcludedPaths as $excluded) {
            if (str_contains($path, $excluded)) {
                return true;
            }
        }

        return false;
    }
}
