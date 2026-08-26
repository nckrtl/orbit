<?php

declare(strict_types=1);

use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallerResolver;
use App\Services\Trust\TrustStoreInstallException;

it('resolves the Linux trust-store installer', function (): void {
    expect(new TrustStoreInstallerResolver('Linux')->resolve())->toBeInstanceOf(LinuxTrustStoreInstaller::class);
});

it('resolves the macOS trust-store installer', function (): void {
    expect(new TrustStoreInstallerResolver('Darwin')->resolve())->toBeInstanceOf(MacOsTrustStoreInstaller::class);
});

it('rejects unsupported trust-store platforms', function (): void {
    expect(fn () => new TrustStoreInstallerResolver('Solaris')->resolve())
        ->toThrow(TrustStoreInstallException::class, 'Solaris');
});
