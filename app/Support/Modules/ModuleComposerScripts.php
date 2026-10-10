<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Script\Event;
use RuntimeException;
use Symfony\Component\Process\Process;

class ModuleComposerScripts
{
    private static string $manifest = 'module.json';

    public static function postAutoloadDump(Event $event): void
    {
        self::artisan($event->getComposer(), $event->getIO(), ['modules:sync']);
    }

    public static function prePackageUninstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        if (!$operation instanceof UninstallOperation) {
            return;
        }

        $composer = $event->getComposer();
        $package = $operation->getPackage();

        if (!self::isModule($composer, $package) || self::isStillLocked($composer, $package)) {
            return;
        }

        self::artisan($composer, $event->getIO(), ['modules:unregister', $package->getName()]);
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
