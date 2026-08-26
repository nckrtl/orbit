<?php

declare(strict_types=1);

namespace App\Services\Trust;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final readonly class RootCertificate
{
    private function __construct(
        public string $pem,
        public string $fingerprint,
    ) {}

    public static function fromPem(string $pem): self
    {
        if (str_contains($pem, 'PRIVATE KEY')) {
            throw new InvalidArgumentException('Root CA material contains private key data.');
        }

        /** @var array{0: list<string>} $matches */
        $matches = [];
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s',
            $pem,
            $matches,
        );

        if (count($matches[0]) !== 1 || trim($matches[0][0]) !== trim($pem)) {
            throw new InvalidArgumentException('Root CA material must contain one PEM certificate.');
        }

        /** @mago-expect analysis:invalid-argument OpenSSL accepts PEM strings at runtime. */
        $certificate = openssl_x509_read(certificate: $pem);

        if (! $certificate instanceof OpenSSLCertificate) {
            throw new InvalidArgumentException('Root CA material is not a certificate.');
        }

        $details = openssl_x509_parse($certificate);
        $publicKey = openssl_pkey_get_public($certificate);
        /** @mago-expect analysis:mixed-assignment OpenSSL certificate extension values are untyped. */
        $basicConstraints = is_array($details)
            ? $details['extensions']['basicConstraints'] ?? null
            : null;

        if (
            ! $publicKey instanceof OpenSSLAsymmetricKey
            || ! is_string($basicConstraints)
            || ! str_contains($basicConstraints, 'CA:TRUE')
            || openssl_x509_verify($certificate, $publicKey) !== 1
        ) {
            throw new InvalidArgumentException('Root CA material is not a self-signed CA certificate.');
        }

        $fingerprint = openssl_x509_fingerprint($certificate, digest_algo: 'sha256');

        if (! is_string($fingerprint) || $fingerprint === '') {
            throw new InvalidArgumentException('Root CA fingerprint could not be calculated.');
        }

        return new self($pem, strtolower($fingerprint));
    }

    public static function fromPath(string $path): self
    {
        $pem = file_get_contents($path);

        if (! is_string($pem)) {
            throw new InvalidArgumentException('Root CA file is not readable.');
        }

        return self::fromPem($pem);
    }
}
