<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('signs a target CSR for only the gateway-approved hostname', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    $target = "{$orbitHome}/target";
    $approvedHostname = 'leaf-signer-approved.app-dev.orbit';
    $unapprovedHostname = 'leaf-signer-unapproved.example';
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    mkdir(directory: $target, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;

    try {
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'genrsa',
                '-out',
                "{$ca}/root.key",
                '4096',
            ]))->succeeded(),
        )->toBeTrue();
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'req',
                '-x509',
                '-new',
                '-key',
                "{$ca}/root.key",
                '-out',
                "{$ca}/root.pem",
                '-days',
                '1',
                '-subj',
                '/CN=Orbit Test Root',
                '-addext',
                'basicConstraints=critical,CA:TRUE',
                '-addext',
                'keyUsage=critical,keyCertSign,cRLSign',
            ]))->succeeded(),
        )->toBeTrue();
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'genrsa',
                '-out',
                "{$target}/leaf.key",
                '2048',
            ]))->succeeded(),
        )->toBeTrue();
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'req',
                '-new',
                '-key',
                "{$target}/leaf.key",
                '-out',
                "{$target}/leaf.csr",
                '-subj',
                "/CN={$unapprovedHostname}",
                '-addext',
                "subjectAltName=DNS:{$unapprovedHostname}",
            ]))->succeeded(),
        )->toBeTrue();
        $request = file_get_contents("{$target}/leaf.csr");
        expect($request)->toBeString();
        $certificate = new OpenSslLeafCertificateSigner($processes, $orbitHome)->sign(
            $approvedHostname,
            $request,
        );
        file_put_contents("{$target}/leaf.pem", $certificate);
        $firstSerial = leaf_certificate_serial($processes, "{$target}/leaf.pem");
        $secondCertificate = new OpenSslLeafCertificateSigner($processes, $orbitHome)->sign(
            $approvedHostname,
            $request,
        );
        file_put_contents("{$target}/leaf-second.pem", $secondCertificate);
        $approvedHost = leaf_certificate_matches_host($processes, "{$target}/leaf.pem", $approvedHostname);
        $unapprovedHost = leaf_certificate_matches_host($processes, "{$target}/leaf.pem", $unapprovedHostname);
        $verified = $processes->run(new ProcessInvocation([
            'openssl',
            'verify',
            '-CAfile',
            "{$ca}/root.pem",
            "{$target}/leaf.pem",
        ]));
        $extensions = leaf_certificate_extensions("{$target}/leaf.pem");
        $text = leaf_certificate_text($processes, "{$target}/leaf.pem");
        $leafPublicKey = openssl_pkey_get_public($certificate);
        $leafPublicKeyDetails = $leafPublicKey !== false
            ? openssl_pkey_get_details($leafPublicKey)
            : false;
        $caddyValidation = leaf_certificate_caddy_validation(
            $processes,
            $target,
            "{$target}/leaf.pem",
            "{$target}/leaf.key",
            $approvedHostname,
        );

        expect($approvedHost)
            ->toBeTrue()
            ->and($unapprovedHost)
            ->toBeFalse()
            ->and($verified->succeeded())
            ->toBeTrue()
            ->and(leaf_certificate_validity_days($processes, "{$target}/leaf.pem"))
            ->toBeIn([396, 397])
            ->and($extensions['basicConstraints'] ?? null)
            ->toBe('CA:FALSE')
            ->and($extensions['keyUsage'] ?? null)
            ->toBe('Digital Signature, Key Encipherment')
            ->and($extensions['extendedKeyUsage'] ?? null)
            ->toBe('TLS Web Server Authentication')
            ->and($extensions['subjectAltName'] ?? null)
            ->toBe("DNS:{$approvedHostname}");
        expect($text)
            ->toContain('X509v3 Basic Constraints: critical', 'X509v3 Key Usage: critical');
        expect(str_contains($text, 'Key Encipherment'))
            ->toBeTrue()
            ->and($leafPublicKeyDetails['type'] ?? null)
            ->toBe(OPENSSL_KEYTYPE_RSA)
            ->and($leafPublicKeyDetails['bits'] ?? null)
            ->toBe(2048)
            ->and(str_contains($text, "DNS:{$unapprovedHostname}"))
            ->toBeFalse()
            ->and($caddyValidation === null || $caddyValidation->succeeded())
            ->toBeTrue()
            ->and(leaf_certificate_serial($processes, "{$target}/leaf-second.pem") === $firstSerial)
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects a hostname mismatch when OpenSSL exits successfully', function (): void {
    $hostname = 'leaf-signer-unapproved.example';
    $result = new CommandResult(
        exitCode: 0,
        stdout: "Hostname {$hostname} does NOT match certificate\n",
        stderr: '',
        durationMs: 1,
        truncated: false,
    );

    expect(leaf_certificate_host_check_matches($result, $hostname))->toBeFalse();
});

it('fails closed when the root CA state is partial', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    file_put_contents(
        "{$ca}/root.pem",
        data: "-----BEGIN CERTIFICATE-----\npartial\n-----END CERTIFICATE-----\n",
    );

    try {
        expect(fn () => new OpenSslLeafCertificateSigner(new NativeProcessRunner, $orbitHome)->rootCertificate())
            ->toThrow(RuntimeException::class, 'partial');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects an Ed25519 target CSR with the approved hostname and SAN', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-rsa-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    $target = "{$orbitHome}/target";
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    mkdir(directory: $target, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;

    try {
        create_leaf_signer_root_ca($processes, $ca);
        expect(
            $processes->run(new ProcessInvocation([
                leaf_openssl_binary(),
                'genpkey',
                '-algorithm',
                'ED25519',
                '-out',
                "{$target}/leaf.key",
            ]))->succeeded(),
        )->toBeTrue();
        expect(
            $processes->run(new ProcessInvocation([
                leaf_openssl_binary(),
                'req',
                '-new',
                '-key',
                "{$target}/leaf.key",
                '-out',
                "{$target}/leaf.csr",
                '-subj',
                '/CN=dev.app-dev.orbit',
                '-addext',
                'subjectAltName=DNS:dev.app-dev.orbit',
            ]))->succeeded(),
        )->toBeTrue();
        $request = file_get_contents("{$target}/leaf.csr");
        expect($request)->toBeString();

        expect(
            fn () => new OpenSslLeafCertificateSigner($processes, $orbitHome)->sign(
                'dev.app-dev.orbit',
                $request,
            ),
        )->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('certificate-request-validate')
                ->and($exception->errorCode)
                ->toBe('app-dev.certificate_request_invalid');
        });
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects root CA material outside its validity window', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;

    try {
        create_leaf_signer_root_ca($processes, $ca);
        $certificate = file_get_contents("{$ca}/root.pem");
        $details = is_string($certificate) ? openssl_x509_parse($certificate) : false;
        $validFrom = is_array($details) ? $details['validFrom_time_t'] ?? null : null;
        $validTo = is_array($details) ? $details['validTo_time_t'] ?? null : null;

        expect($validFrom)->toBeInt()->and($validTo)->toBeInt();

        foreach ([$validFrom - 1, $validTo + 1] as $now) {
            expect(
                fn () => new OpenSslLeafCertificateSigner(
                    $processes,
                    $orbitHome,
                    static fn (): int => $now,
                )->rootCertificate(),
            )
                ->toThrow(RuntimeException::class, 'material is invalid');
        }
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects root CA material that is not RSA 4096', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;

    try {
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'genrsa',
                '-out',
                "{$ca}/root.key",
                '2048',
            ]))->succeeded(),
        )->toBeTrue();
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'req',
                '-x509',
                '-new',
                '-key',
                "{$ca}/root.key",
                '-out',
                "{$ca}/root.pem",
                '-days',
                '1',
                '-subj',
                '/CN=Orbit Test Root',
                '-addext',
                'basicConstraints=critical,CA:TRUE',
                '-addext',
                'keyUsage=critical,keyCertSign,cRLSign',
            ]))->succeeded(),
        )->toBeTrue();

        expect(fn () => new OpenSslLeafCertificateSigner($processes, $orbitHome)->rootCertificate())
            ->toThrow(RuntimeException::class, 'material is invalid');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects a root certificate and private key mismatch', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-leaf-signer-'.Str::uuid();
    $ca = "{$orbitHome}/ca";
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;

    try {
        create_leaf_signer_root_ca($processes, $ca);
        expect(
            $processes->run(new ProcessInvocation([
                'openssl',
                'genrsa',
                '-out',
                "{$ca}/root.key",
                '4096',
            ]))->succeeded(),
        )->toBeTrue();

        expect(fn () => new OpenSslLeafCertificateSigner($processes, $orbitHome)->rootCertificate())
            ->toThrow(RuntimeException::class, 'material is invalid');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

function leaf_certificate_serial(NativeProcessRunner $processes, string $certificatePath): string
{
    return trim($processes->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $certificatePath,
        '-noout',
        '-serial',
    ]))->stdout);
}

function leaf_certificate_validity_days(NativeProcessRunner $processes, string $certificatePath): int
{
    $dates = $processes->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $certificatePath,
        '-noout',
        '-dates',
    ]))->stdout;
    preg_match('/notBefore=(.+)/', $dates, $notBefore);
    preg_match('/notAfter=(.+)/', $dates, $notAfter);
    $startsAt = new DateTimeImmutable($notBefore[1]);
    $expiresAt = new DateTimeImmutable($notAfter[1]);
    $days = $startsAt->diff($expiresAt)->days;

    return is_int($days) ? $days : 0;
}

function create_leaf_signer_root_ca(NativeProcessRunner $processes, string $ca): void
{
    $key = $processes->run(new ProcessInvocation([
        'openssl',
        'genrsa',
        '-out',
        "{$ca}/root.key",
        '4096',
    ]));
    $certificate = $processes->run(new ProcessInvocation([
        'openssl',
        'req',
        '-x509',
        '-new',
        '-key',
        "{$ca}/root.key",
        '-out',
        "{$ca}/root.pem",
        '-days',
        '1',
        '-subj',
        '/CN=Orbit Test Root',
        '-addext',
        'basicConstraints=critical,CA:TRUE',
        '-addext',
        'keyUsage=critical,keyCertSign,cRLSign',
    ]));

    if (! $key->succeeded() || ! $certificate->succeeded()) {
        throw new RuntimeException('Could not create the leaf signer root CA fixture.');
    }
}

/** @return array<array-key, mixed> */
function leaf_certificate_extensions(string $certificatePath): array
{
    $certificate = file_get_contents($certificatePath);
    $details = is_string($certificate) ? openssl_x509_parse($certificate) : false;
    $extensions = is_array($details) ? $details['extensions'] : null;

    return is_array($extensions) ? $extensions : [];
}

function leaf_certificate_text(NativeProcessRunner $processes, string $certificatePath): string
{
    return $processes->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $certificatePath,
        '-noout',
        '-text',
    ]))->stdout;
}

function leaf_certificate_matches_host(
    NativeProcessRunner $processes,
    string $certificatePath,
    string $hostname,
): bool {
    $result = $processes->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $certificatePath,
        '-noout',
        '-checkhost',
        $hostname,
    ]));

    if (! $result->succeeded() && str_contains($result->stderr, 'unknown option -checkhost')) {
        $extensions = leaf_certificate_extensions($certificatePath);

        return ($extensions['subjectAltName'] ?? null) === "DNS:{$hostname}";
    }

    return leaf_certificate_host_check_matches($result, $hostname);
}

function leaf_openssl_binary(): string
{
    return is_executable('/opt/homebrew/bin/openssl') ? '/opt/homebrew/bin/openssl' : 'openssl';
}

function leaf_certificate_host_check_matches(CommandResult $result, string $hostname): bool
{
    return $result->succeeded() && trim($result->stdout) === "Hostname {$hostname} does match certificate";
}

function leaf_certificate_caddy_validation(
    NativeProcessRunner $processes,
    string $directory,
    string $certificatePath,
    string $privateKeyPath,
    string $hostname,
): ?CommandResult {
    if (new Symfony\Component\Process\ExecutableFinder()->find('caddy') === null) {
        return null;
    }

    $configurationPath = $directory.'/Caddyfile';
    file_put_contents($configurationPath, <<<CADDYFILE
        https://{$hostname} {
            tls {$certificatePath} {$privateKeyPath}
            respond "ok"
        }
        CADDYFILE);

    return $processes->run(new ProcessInvocation([
        'caddy',
        'validate',
        '--config',
        $configurationPath,
        '--adapter',
        'caddyfile',
    ]));
}
