<?php

declare(strict_types=1);

namespace App\Services\Trust;

use Illuminate\Support\Facades\Process;
use Throwable;

final readonly class MacOsTrustStoreInstaller implements TrustStoreInstaller
{
    private const string SYSTEM_KEYCHAIN = '/Library/Keychains/System.keychain';

    public function isTrusted(RootCertificate $certificate, string $label): bool
    {
        try {
            $result = Process::timeout(30)->run([
                'security',
                'find-certificate',
                '-a',
                '-p',
                self::SYSTEM_KEYCHAIN,
            ]);

            if (! $result->successful()) {
                return false;
            }

            $matches = [];
            preg_match_all(
                pattern: '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s',
                subject: $result->output(),
                matches: $matches,
            );

            foreach ($matches[0] ?? [] as $pem) {
                try {
                    $candidate = RootCertificate::fromPem($pem);
                } catch (Throwable) {
                    continue;
                }

                if (hash_equals($certificate->fingerprint, $candidate->fingerprint)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function install(string $certificatePath, string $label): void
    {
        try {
            $result = Process::timeout(120)
                ->tty()
                ->run([
                    'sudo',
                    'security',
                    'add-trusted-cert',
                    '-d',
                    '-r',
                    'trustRoot',
                    '-k',
                    self::SYSTEM_KEYCHAIN,
                    $certificatePath,
                ]);
        } catch (Throwable $exception) {
            throw new TrustStoreInstallException(
                'Could not install the gateway root CA on macOS.',
                previous: $exception,
            );
        }

        if (! $result->successful()) {
            throw new TrustStoreInstallException('Could not install the gateway root CA on macOS.');
        }
    }
}
