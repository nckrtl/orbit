<?php

declare(strict_types=1);

namespace App\Services\Trust;

use Illuminate\Support\Facades\Process;
use Throwable;

final readonly class LinuxTrustStoreInstaller implements TrustStoreInstaller
{
    public function __construct(
        private string $trustDirectory = '/usr/local/share/ca-certificates',
        private string $trustBundlePath = '/etc/ssl/certs/ca-certificates.crt',
    ) {}

    public function isTrusted(RootCertificate $certificate, string $label): bool
    {
        $target = $this->targetPath($label);

        if (! is_file($target)) {
            return false;
        }

        try {
            if (! hash_equals($certificate->fingerprint, RootCertificate::fromPath($target)->fingerprint)) {
                return false;
            }

            return Process::timeout(30)->run([
                'openssl',
                'verify',
                '-CAfile',
                $this->trustBundlePath,
                $target,
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function install(string $certificatePath, string $label): void
    {
        $copy = Process::timeout(120)->run([
            'sudo',
            'install',
            '-m',
            '0644',
            '--',
            $certificatePath,
            $this->targetPath($label),
        ]);

        if (! $copy->successful()) {
            throw new TrustStoreInstallException('Could not install the gateway root CA on Linux.');
        }

        $update = Process::timeout(120)->run(['sudo', 'update-ca-certificates']);

        if (! $update->successful()) {
            throw new TrustStoreInstallException('Could not refresh the Linux certificate trust store.');
        }
    }

    private function targetPath(string $label): string
    {
        $filename =
            'orbit-gateway-ca-'
            .substr(
                string: hash('sha256', $label),
                offset: 0,
                length: 16,
            )
            .'.crt';

        return rtrim(string: $this->trustDirectory, characters: '/').'/'.$filename;
    }
}
