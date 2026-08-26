<?php

declare(strict_types=1);

namespace App\Services\Trust;

use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity The store fails closed at each filesystem safety boundary. */
final readonly class GatewayRootCaStore
{
    public function store(string $gatewayName, RootCertificate $certificate): string
    {
        $home = rtrim(string: (string) config('orbit.home'), characters: '/');
        $slug = preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: strtolower($gatewayName));
        $slug = is_string($slug) ? trim(string: $slug, characters: '-') : '';
        $slug = $slug !== '' ? substr(string: $slug, offset: 0, length: 48) : 'gateway';
        $gatewayDirectory = $home
        .'/gateways/'
        .$slug
        .'-'
        .substr(
            string: hash('sha256', $gatewayName),
            offset: 0,
            length: 12,
        );
        $directory = $gatewayDirectory.'/ca';

        foreach ([$gatewayDirectory, $directory] as $protectedDirectory) {
            if (is_link($protectedDirectory)) {
                throw new RuntimeException('The local gateway CA directory is unsafe.');
            }

            if (
                ! is_dir($protectedDirectory)
                && ! mkdir(directory: $protectedDirectory, permissions: 0o700, recursive: true)
                && ! is_dir($protectedDirectory)
            ) {
                throw new RuntimeException('Could not create the local gateway CA directory.');
            }

            if (! chmod(filename: $protectedDirectory, permissions: 0o700)) {
                throw new RuntimeException('Could not protect the local gateway CA directory.');
            }
        }

        $path = $directory.'/'.$certificate->fingerprint.'.pem';

        if (is_link($path)) {
            throw new RuntimeException('The local gateway CA path is unsafe.');
        }

        if (is_file($path)) {
            $stored = RootCertificate::fromPath($path);

            if (! hash_equals($certificate->fingerprint, $stored->fingerprint)) {
                throw new RuntimeException('Stored gateway CA fingerprint does not match its path.');
            }

            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException('Could not protect the local gateway CA certificate.');
            }

            return $path;
        }

        $candidate = $directory.'/.'.$certificate->fingerprint.'.'.bin2hex(random_bytes(8));

        try {
            if (file_put_contents($candidate, $certificate->pem, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the local gateway CA certificate.');
            }

            if (! chmod(filename: $candidate, permissions: 0o600)) {
                throw new RuntimeException('Could not protect the local gateway CA certificate.');
            }

            if (! rename($candidate, $path)) {
                throw new RuntimeException('Could not install the local gateway CA certificate.');
            }
        } catch (Throwable $exception) {
            if (is_file($candidate)) {
                unlink($candidate);
            }

            throw $exception;
        }

        return $path;
    }
}
