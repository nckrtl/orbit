<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

final readonly class LocalRuntimeDependencies
{
    /**
     * @param  list<string>  $javascriptLockFiles
     */
    public static function inspect(
        bool $composerJsonFile,
        bool $composerLockFile,
        bool $vendorPresent,
        bool $vendorSymlink,
        bool $packageJsonFile,
        array $javascriptLockFiles,
        bool $nodeModulesPresent,
        bool $nodeModulesSymlink,
        ?int $sourceTreeLastActivityUnix = null,
    ): RuntimeDependencyState {
        $vendorReconstructable = $composerJsonFile && $composerLockFile && ! $vendorSymlink;
        $nodeModulesReconstructable = $packageJsonFile
            && self::javascriptLockFamilies($javascriptLockFiles) === 1
            && ! $nodeModulesSymlink;

        return new RuntimeDependencyState(
            vendorReconstructable: $vendorReconstructable,
            vendorPresent: $vendorPresent && ! $vendorSymlink,
            nodeModulesReconstructable: $nodeModulesReconstructable,
            nodeModulesPresent: $nodeModulesPresent && ! $nodeModulesSymlink,
            sourceTreeLastActivityUnix: $sourceTreeLastActivityUnix,
        );
    }

    /** @param list<string> $javascriptLockFiles */
    public static function javascriptLockFamilies(array $javascriptLockFiles): int
    {
        $families = [];

        foreach ($javascriptLockFiles as $file) {
            $family = match ($file) {
                'package-lock.json' => 'npm',
                'yarn.lock' => 'yarn',
                'pnpm-lock.yaml' => 'pnpm',
                'bun.lock', 'bun.lockb' => 'bun',
                default => null,
            };

            if ($family !== null) {
                $families[$family] = true;
            }
        }

        return count($families);
    }
}
