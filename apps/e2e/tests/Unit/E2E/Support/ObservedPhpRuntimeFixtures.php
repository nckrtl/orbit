<?php

declare(strict_types=1);

use App\E2E\Value\PhpRuntimeInventory;

/** @return array{php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>} */
function malformedObservedPhpRuntime(string $fixture): array
{
    $packages = array_fill_keys(PhpRuntimeInventory::PACKAGES, '8.5.10-sury');
    $packages[PhpRuntimeInventory::PCOV_PACKAGE] = '1.0.12-sury';
    $runtime = [
        'php_version' => '8.5.10',
        'fpm_version' => '8.5.10',
        'pcov_version' => '1.0.12',
        'package_versions' => $packages,
    ];

    match ($fixture) {
        'php-version' => $runtime['php_version'] = $runtime['fpm_version'] = '8.5',
        'pcov-version' => $runtime['pcov_version'] = '1.0',
        'package-version' => $runtime['package_versions']['php8.5-cli'] = "8.5.10-sury\rbroken",
        default => throw new InvalidArgumentException("Unknown malformed runtime fixture [{$fixture}]."),
    };

    return $runtime;
}
