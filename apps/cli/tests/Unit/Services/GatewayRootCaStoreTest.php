<?php

declare(strict_types=1);

use App\Services\Trust\GatewayRootCaStore;
use App\Services\Trust\RootCertificate;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-ca-store-'.Str::uuid();
    $this->externalDirectory = sys_get_temp_dir().'/orbit-ca-external-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    $this->certificate = RootCertificate::fromPem(root_ca_store_test_certificate($this->orbitHome.'/fixture'));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
    new Filesystem()->deleteDirectory($this->externalDirectory);
});

it('rejects a symlinked named gateway directory', function (): void {
    $gatewayDirectory = $this->orbitHome.'/gateways/test-gateway-7c27512b7c3e';
    mkdir($this->orbitHome.'/gateways', permissions: 0o700, recursive: true);
    mkdir($this->externalDirectory, permissions: 0o700, recursive: true);
    symlink($this->externalDirectory, $gatewayDirectory);

    expect(fn () => new GatewayRootCaStore()->store('test-gateway', $this->certificate))
        ->toThrow(RuntimeException::class, 'local gateway CA directory is unsafe');
    expect($this->externalDirectory.'/ca')->not->toBeDirectory();
});

it('rejects a symlinked fingerprint path', function (): void {
    $caDirectory = $this->orbitHome.'/gateways/test-gateway-7c27512b7c3e/ca';
    mkdir($caDirectory, permissions: 0o700, recursive: true);
    mkdir($this->externalDirectory, permissions: 0o700, recursive: true);
    $externalCertificate = $this->externalDirectory.'/root.pem';
    file_put_contents($externalCertificate, $this->certificate->pem);
    $fingerprintPath = $caDirectory.'/'.$this->certificate->fingerprint.'.pem';
    symlink($externalCertificate, $fingerprintPath);

    expect(fn () => new GatewayRootCaStore()->store('test-gateway', $this->certificate))
        ->toThrow(RuntimeException::class, 'local gateway CA path is unsafe');
    expect(is_link($fingerprintPath))->toBeTrue();
});

function root_ca_store_test_certificate(string $directory): string
{
    mkdir(directory: $directory, permissions: 0o700, recursive: true);
    $configPath = $directory.'/openssl.cnf';
    file_put_contents($configPath, <<<'OPENSSL'
        [req]
        distinguished_name = subject
        x509_extensions = v3_ca
        prompt = no

        [subject]
        CN = Orbit CA Store Test Root CA

        [v3_ca]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid:always
        OPENSSL);
    $privateKey = openssl_pkey_new([
        'config' => $configPath,
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $certificate = openssl_csr_sign(
        openssl_csr_new(
            ['commonName' => 'Orbit CA Store Test Root CA'],
            $privateKey,
            ['config' => $configPath],
        ),
        ca_certificate: null,
        private_key: $privateKey,
        days: 1,
        options: ['config' => $configPath, 'x509_extensions' => 'v3_ca'],
    );
    openssl_x509_export($certificate, $pem);

    return $pem;
}
