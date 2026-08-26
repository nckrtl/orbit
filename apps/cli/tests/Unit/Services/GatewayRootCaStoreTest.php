<?php

declare(strict_types=1);

use App\Services\Trust\GatewayRootCaStore;
use App\Services\Trust\RootCertificate;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
    $key = $directory.'/root.key';
    $certificate = $directory.'/root.pem';
    new Process([
        'openssl',
        'genpkey',
        '-algorithm',
        'ED25519',
        '-out',
        $key,
    ])->mustRun();
    new Process([
        'openssl',
        'req',
        '-x509',
        '-new',
        '-key',
        $key,
        '-out',
        $certificate,
        '-days',
        '1',
        '-subj',
        '/CN=Orbit CA Store Test Root CA',
    ])->mustRun();

    return (string) file_get_contents($certificate);
}
