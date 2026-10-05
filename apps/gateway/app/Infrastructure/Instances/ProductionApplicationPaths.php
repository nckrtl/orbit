<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\SourceControl\ApplicationDirectory;

final class ProductionApplicationPaths
{
    public static function render(string $program, ?string $root, ?string $applicationPath = null): string
    {
        $suffix = $root === 'public' || (is_string($root) && str_ends_with($root, '/public'))
            ? ApplicationDirectory::resolve('', $root)
            : '';
        if ($applicationPath !== null) {
            // Null web roots compose to the app path itself and keep release-root links.
            $suffix = $root === $applicationPath ? '' : ApplicationDirectory::resolvePath('', $applicationPath);
        }
        $target = str_repeat('../', 2 + substr_count($suffix, '/')).'.env';

        $assignment = $suffix === '' ? '' : 'application_suffix='.escapeshellarg($suffix)."\n";

        return $assignment.strtr($program, [
            '__APPLICATION_SUFFIX__' => $suffix === '' ? '' : '${application_suffix}',
            '__ENVIRONMENT_TARGET__' => $target,
            '__ENVIRONMENT_PATH__' => $suffix === '' ? '.env' : '"${application_suffix#/}/.env"',
        ]);
    }
}
