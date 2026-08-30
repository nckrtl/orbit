<?php

declare(strict_types=1);

namespace App\Services\Trust;

class TrustStoreInstallerResolver
{
    public function __construct(
        private readonly ?string $platform = null,
    ) {}

    public function resolve(): TrustStoreInstaller
    {
        $platform = $this->platform ?? PHP_OS_FAMILY;

        return match (strtolower($platform)) {
            'darwin' => new MacOsTrustStoreInstaller,
            'linux' => new LinuxTrustStoreInstaller,
            default => throw new TrustStoreInstallException(
                "Automatic root CA trust is not supported on [{$platform}].",
            ),
        };
    }
}
