<?php

declare(strict_types=1);

namespace App\Support\Conventions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class HardcodedCopyScanner
{
    /** @var list<string> */
    private array $fixedFormatFiles = ['DatevBookingBatch.php', 'VonageMessageProvider.php'];

    private string $germanLetters = '/[\x{00E4}\x{00F6}\x{00FC}\x{00C4}\x{00D6}\x{00DC}\x{00DF}]/u';

    private string $germanWords = '/\b(sie|ihre?n?|nicht|bitte|wurde|werden|wird|keine?n?|ist|und|oder|der|die|das|des|dem|den|zeigt|auf|f\x{00FC}r)\b/iu';

    /**
     * @return list<string>
     */
    public function scan(string $directory): array
    {
        $offenders = [];

        foreach ($this->files($directory) as $file) {
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $location = $file->getPathname().':'.$token[2];

                if (!isset($offenders[$location]) && $this->isGerman($token[1])) {
                    $offenders[$location] = $location.'  '.mb_substr(trim($token[1]), 0, 80);
                }
            }
        }

        $offenders = array_values($offenders);

        sort($offenders);

        return $offenders;
    }

    private function isGerman(string $text): bool
    {
        if (preg_match($this->germanLetters, $text) === 1) {
            return true;
        }

        return preg_match('/\S\s+\S/u', $text) === 1 && preg_match($this->germanWords, $text) === 1;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function files(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (in_array($file->getFilename(), $this->fixedFormatFiles, true)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }
}
