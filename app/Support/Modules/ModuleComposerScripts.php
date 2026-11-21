<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\InstallerEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Script\Event;
use RuntimeException;
use Symfony\Component\Process\Process;

class ModuleComposerScripts
{
    private static string $manifest = 'module.json';

    private static string $frontendRebuildHint = 'The frontend files of removed modules stay in public/build until you rebuild the frontend: vendor/bin/sail npm run build';

    public static function postAutoloadDump(Event $event): void
    {
        self::artisan($event->getComposer(), $event->getIO(), ['modules:sync', '--skip-without-database', ...$event->isDevMode() ? [] : ['--allow-missing-code']]);
    }

    public static function preOperationsExec(InstallerEvent $event): void
    {
        $transaction = $event->getTransaction();

        if ($transaction === null || !$event->isExecutingOperations()) {
            return;
        }

        $composer = $event->getComposer();
        $io = $event->getIO();
        $leaving = array_values(array_filter(
            array_map(static fn (OperationInterface $operation): ?PackageInterface => $operation instanceof UninstallOperation ? $operation->getPackage() : null, $transaction->getOperations()),
            static fn (?PackageInterface $package): bool => $package !== null && self::isModule($composer, $package) && !self::isStillLocked($composer, $package),
        ));

        foreach (self::dependentsFirst($leaving) as $package) {
            self::artisan($composer, $io, ['modules:unregister', $package->getName()]);
        }

        if ($leaving !== []) {
            $io->write(self::$frontendRebuildHint);
        }
    }

    /**
     * @param  list<PackageInterface>  $packages
     * @return list<PackageInterface>
     */
    private static function dependentsFirst(array $packages): array
    {
        $ordered = [];

        foreach ($packages as $package) {
            self::placeAfterDependents($package, $packages, $ordered, []);
        }

        return $ordered;
    }

    /**
     * @param  list<PackageInterface>  $packages
     * @param  list<PackageInterface>  $ordered
     * @param  list<string>  $visiting
     */
    private static function placeAfterDependents(PackageInterface $package, array $packages, array &$ordered, array $visiting): void
    {
        if (in_array($package, $ordered, true) || in_array($package->getName(), $visiting, true)) {
            return;
        }

        foreach ($packages as $candidate) {
            if (in_array($package->getName(), array_keys([...$candidate->getRequires(), ...$candidate->getSuggests()]), true)) {
                self::placeAfterDependents($candidate, $packages, $ordered, [...$visiting, $package->getName()]);
            }
        }

        $ordered[] = $package;
    }

    private static function isModule(Composer $composer, PackageInterface $package): bool
    {
        return is_file($composer->getInstallationManager()->getInstallPath($package).'/'.self::$manifest);
    }

    private static function isStillLocked(Composer $composer, PackageInterface $package): bool
    {
        $locker = $composer->getLocker();

        return $locker->isLocked() && $locker->getLockedRepository(true)->findPackage($package->getName(), '*') !== null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function artisan(Composer $composer, IOInterface $io, array $arguments): void
    {
        $root = dirname((string) realpath($composer->getConfig()->get('vendor-dir')));

        if (!is_file($root.'/artisan')) {
            return;
        }

        $cache = sys_get_temp_dir().'/nubos-modules-'.bin2hex(random_bytes(8));
        $caches = ['APP_PACKAGES_CACHE' => "{$cache}-packages.php", 'APP_SERVICES_CACHE' => "{$cache}-services.php"];
        $process = new Process([PHP_BINARY, 'artisan', ...$arguments, ...$io->isInteractive() ? [] : ['--no-interaction']], $root, $caches);
        $process->setTimeout(null);

        if ($io->isInteractive() && Process::isTtySupported()) {
            $process->setTty(true);
        }

        try {
            $process->run(function (string $type, string $output) use ($io): void {
                $io->write($output, false);
            });
        } finally {
            foreach (array_filter($caches, is_file(...)) as $file) {
                unlink($file);
            }
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('php artisan '.implode(' ', $arguments).' failed with exit code '.$process->getExitCode().'.');
        }
    }
}
