<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Files\NativeAtomicSymlinkPublisher;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/*
 * Nothing renews the leaves behind a static `tls <cert> <key>` pair: Caddy never touches them, and
 * there is no scheduler. Convergence is the only renewal opportunity, so it has to notice both an
 * expired leaf and one that is about to expire. These tests pin all three outcomes for both
 * consumers of the static publication path.
 */

it('leaves a current certificate untouched', function (string $hostname): void {
    $orbitHome = renewal_orbit_home();

    try {
        $issuer = renewal_issuer($orbitHome);
        $first = $issuer->issue($hostname, '10.44.0.1');
        $serial = renewal_serial($first->certificatePath);

        $second = $issuer->issue($hostname, '10.44.0.1');

        expect(renewal_serial($second->certificatePath))->toBe($serial);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with(['gateway.orbit', 'metrics.orbit']);

it('re-issues a certificate that expires inside the renewal margin', function (string $hostname): void {
    $orbitHome = renewal_orbit_home();

    try {
        $issuer = renewal_issuer($orbitHome);
        $paths = $issuer->issue($hostname, '10.44.0.1');
        $original = renewal_serial($paths->certificatePath);
        // Ten days of life left: still trusted, but well inside the thirty day margin.
        renewal_replace_leaf($orbitHome, $paths, $hostname, ['-days', '10']);

        $renewed = $issuer->issue($hostname, '10.44.0.1');

        expect(renewal_serial($renewed->certificatePath))
            ->not
            ->toBe($original)
            ->and(renewal_expires_at($renewed->certificatePath))
            ->toBeGreaterThan(new DateTimeImmutable('+300 days'));
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with(['gateway.orbit', 'metrics.orbit']);

it('re-issues a certificate that has already expired', function (string $hostname): void {
    $orbitHome = renewal_orbit_home();

    try {
        $issuer = renewal_issuer($orbitHome);
        $paths = $issuer->issue($hostname, '10.44.0.1');
        $original = renewal_serial($paths->certificatePath);
        renewal_backdate_leaf($orbitHome, $paths, $hostname);

        expect(renewal_expires_at($paths->certificatePath))->toBeLessThan(new DateTimeImmutable);

        $renewed = $issuer->issue($hostname, '10.44.0.1');

        expect(renewal_serial($renewed->certificatePath))
            ->not
            ->toBe($original)
            ->and(renewal_expires_at($renewed->certificatePath))
            ->toBeGreaterThan(new DateTimeImmutable);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with(['gateway.orbit', 'metrics.orbit']);

function renewal_openssl(): string
{
    return is_executable('/opt/homebrew/bin/openssl') ? '/opt/homebrew/bin/openssl' : 'openssl';
}

function renewal_run(array $arguments): void
{
    expect(new NativeProcessRunner()->run(new ProcessInvocation($arguments, timeout: 60.0))->succeeded())
        ->toBeTrue();
}

function renewal_orbit_home(): string
{
    $orbitHome = sys_get_temp_dir().'/orbit-certificate-renewal-'.Str::uuid();
    $caDirectory = $orbitHome.'/ca';
    mkdir(directory: $caDirectory, permissions: 0o700, recursive: true);
    renewal_run([renewal_openssl(), 'genrsa', '-out', $caDirectory.'/root.key', '4096']);
    renewal_run([
        renewal_openssl(),
        'req',
        '-x509',
        '-new',
        '-key',
        $caDirectory.'/root.key',
        '-out',
        $caDirectory.'/root.pem',
        '-days',
        '3650',
        '-subj',
        '/CN=Orbit Root CA',
    ]);

    return $orbitHome;
}

function renewal_issuer(string $orbitHome): OpenSslGatewayCertificateIssuer
{
    $processes = new NativeProcessRunner;

    return new OpenSslGatewayCertificateIssuer(
        processes: $processes,
        validator: new OpenSslGatewayCertificateValidator($processes),
        links: new NativeAtomicSymlinkPublisher,
        orbitHome: $orbitHome,
    );
}

function renewal_serial(string $certificatePath): string
{
    $serial = shell_exec(
        renewal_openssl().' x509 -in '.escapeshellarg($certificatePath).' -noout -serial',
    );

    return trim((string) $serial);
}

function renewal_expires_at(string $certificatePath): DateTimeImmutable
{
    $dates = (string) shell_exec(
        renewal_openssl().' x509 -in '.escapeshellarg($certificatePath).' -noout -enddate',
    );
    $matches = [];
    preg_match('/notAfter=(.+)/', $dates, $matches);

    return new DateTimeImmutable(trim($matches[1]));
}

/**
 * Re-sign the leaf that is already published, reusing its private key so the validity window is the
 * only thing that changes. Anything else would let these tests pass for the wrong reason.
 */
function renewal_extensions(string $orbitHome, string $hostname): string
{
    $path = $orbitHome.'/leaf.ext';
    file_put_contents($path, <<<EXTENSIONS
        [gateway]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature,keyEncipherment
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:{$hostname},IP:10.44.0.1
        EXTENSIONS);

    return $path;
}

function renewal_request(string $orbitHome, GatewayCertificatePaths $paths, string $hostname): string
{
    $request = $orbitHome.'/leaf.csr';
    renewal_run([
        renewal_openssl(),
        'req',
        '-new',
        '-key',
        $paths->privateKeyPath,
        '-out',
        $request,
        '-subj',
        "/CN={$hostname}",
    ]);

    return $request;
}

function renewal_replace_leaf(
    string $orbitHome,
    GatewayCertificatePaths $paths,
    string $hostname,
    array $validity,
): void {
    renewal_run([
        renewal_openssl(),
        'x509',
        '-req',
        '-in',
        renewal_request($orbitHome, $paths, $hostname),
        '-CA',
        $orbitHome.'/ca/root.pem',
        '-CAkey',
        $orbitHome.'/ca/root.key',
        '-set_serial',
        '0x'.bin2hex(random_bytes(16)),
        '-out',
        $paths->certificatePath,
        '-sha256',
        '-extfile',
        renewal_extensions($orbitHome, $hostname),
        '-extensions',
        'gateway',
        ...$validity,
    ]);
}

/**
 * `openssl x509 -req` cannot date a certificate into the past on every OpenSSL we support, so the
 * expired fixture is minted through `openssl ca`, which has taken -startdate/-enddate for years.
 * The window is a fixed 397 days that closed in 2025, so the fixture stays expired forever and the
 * test never depends on the wall clock.
 */
function renewal_backdate_leaf(string $orbitHome, GatewayCertificatePaths $paths, string $hostname): void
{
    $ca = $orbitHome.'/backdate';
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    touch($ca.'/index.txt');
    file_put_contents($ca.'/serial', "01\n");
    file_put_contents($ca.'/openssl.cnf', <<<CONFIG
        [ca]
        default_ca = backdate
        [backdate]
        dir = {$ca}
        database = \$dir/index.txt
        serial = \$dir/serial
        new_certs_dir = \$dir
        certificate = {$orbitHome}/ca/root.pem
        private_key = {$orbitHome}/ca/root.key
        default_md = sha256
        policy = backdate_policy
        email_in_dn = no
        rand_serial = no
        unique_subject = no
        [backdate_policy]
        commonName = supplied
        CONFIG);
    renewal_run([
        renewal_openssl(),
        'ca',
        '-batch',
        '-notext',
        '-config',
        $ca.'/openssl.cnf',
        '-in',
        renewal_request($orbitHome, $paths, $hostname),
        '-out',
        $paths->certificatePath,
        '-startdate',
        '20240101000000Z',
        '-enddate',
        '20250201000000Z',
        '-extfile',
        renewal_extensions($orbitHome, $hostname),
        '-extensions',
        'gateway',
    ]);
}
