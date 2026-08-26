<?php

declare(strict_types=1);

use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallerResolver;
use App\Services\Trust\TrustStoreInstallException;

it('resolves the Linux trust-store installer', function (): void {
    expect(new TrustStoreInstallerResolver('Linux')->resolve())->toBeInstanceOf(LinuxTrustStoreInstaller::class);
});

it('rejects unsupported trust-store platforms', function (string $platform): void {
    expect(fn () => new TrustStoreInstallerResolver($platform)->resolve())
        ->toThrow(TrustStoreInstallException::class, $platform);
})->with([
    'unsupported platform' => 'Solaris',
    'retired platform' => 'Darwin',
]);
