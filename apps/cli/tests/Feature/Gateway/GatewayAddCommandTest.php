<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayAddCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallerResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\FetchRootCaCertificateRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    $this->certificate = gateway_add_test_certificate($this->orbitHome);
    $this->fingerprint = openssl_x509_fingerprint($this->certificate, digest_algo: 'sha256');
    $this->trustStore = $this->orbitHome.'/system-trust';
    app()->instance(
        TrustStoreInstallerResolver::class,
        new class($this->trustStore) extends TrustStoreInstallerResolver {
            public function __construct(
                private readonly string $trustStore,
            ) {}

            public function resolve(): LinuxTrustStoreInstaller
            {
                return new LinuxTrustStoreInstaller($this->trustStore);
            }
        },
    );
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    Process::fake(function (PendingProcess $process) {
        if (is_array($process->command) && ($process->command[1] ?? null) === 'install') {
            $target = $process->command[6];

            if (! is_dir(dirname($target))) {
                mkdir(directory: dirname($target), permissions: 0o755, recursive: true);
            }

            copy($process->command[5], $target);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @mago-expect lint:halstead The command contract keeps gateway discovery, trust, and activation assertions together. */
describe(GatewayAddCommand::class, function (): void {
    it('adds and activates the first gateway profile from a bare IP', function (): void {
        expect(class_exists(GatewayAddCommand::class))->toBeTrue();

        $exitCode = Artisan::call('gateway:add', [
            'gateway' => '10.70.0.1',
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        expect($output)->toContain('"name":"default"');
        expect($exitCode)->toBe(0);

        $profile = app(GatewayConfigRepository::class)->active();

        expect($profile?->name)
            ->toBe('default')
            ->and($profile?->url)
            ->toBe('https://10.70.0.1')
            ->and($profile?->caPath)
            ->toBe($this->orbitHome.'/gateways/default-37a8eec1ce19/ca/'.$this->fingerprint.'.pem');
    });

    it('preserves the active profile when a later HTTPS origin is added without use', function (): void {
        $firstExitCode = Artisan::call('gateway:add', ['gateway' => '10.70.0.1']);

        expect($firstExitCode)->toBe(0);

        $this
            ->artisan('gateway:add', [
                'gateway' => 'https://10.80.0.1/',
                '--name' => 'production',
                '--json' => true,
            ])
            ->expectsOutputToContain('"active":false')
            ->assertExitCode(0);

        $repository = app(GatewayConfigRepository::class);

        expect($repository->active()?->name)
            ->toBe('default')
            ->and($repository->find('production')?->url)
            ->toBe('https://10.80.0.1');
    });

    it('rejects a non-HTTPS gateway URL', function (): void {
        expect(class_exists(GatewayAddCommand::class))->toBeTrue();

        $this
            ->artisan('gateway:add', [
                'gateway' => 'http://10.70.0.1',
            ])
            ->expectsOutputToContain('Gateway URL must use HTTPS.')
            ->assertExitCode(1);

        expect(app(GatewayConfigRepository::class)->find('default'))->toBeNull();
    });

    it('rejects unsafe gateway profile input as one safe json error', function (
        array $arguments,
        string $message,
    ): void {
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_invalid',
                'message' => $message,
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:add', [...$arguments, '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('profile-secret');
        expect(is_file($this->orbitHome.'/config.json'))->toBeFalse();
    })->with([
        'invalid profile name' => [
            ['gateway' => 'https://10.70.0.1', '--name' => "test\nprofile-secret"],
            'Gateway profile name is invalid.',
        ],
        'credential-shaped profile name' => [
            ['gateway' => 'https://10.70.0.1', '--name' => 'API_TOKEN=profile-secret'],
            'Gateway profile name is invalid.',
        ],
        'path-like profile name' => [
            ['gateway' => 'https://10.70.0.1', '--name' => '../profile-secret'],
            'Gateway profile name is invalid.',
        ],
        'profile name with a space' => [
            ['gateway' => 'https://10.70.0.1', '--name' => 'test profile-secret'],
            'Gateway profile name is invalid.',
        ],
        'uppercase profile name' => [
            ['gateway' => 'https://10.70.0.1', '--name' => 'Production'],
            'Gateway profile name is invalid.',
        ],
        'profile name above maximum length' => [
            [
                'gateway' => 'https://10.70.0.1',
                '--name' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ],
            'Gateway profile name is invalid.',
        ],
        'credential-bearing URL' => [
            ['gateway' => 'https://user:profile-secret@10.70.0.1'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL base path' => [
            ['gateway' => 'https://10.70.0.1/profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL query' => [
            ['gateway' => 'https://10.70.0.1?token=profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL fragment' => [
            ['gateway' => 'https://10.70.0.1#profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'port zero' => [
            ['gateway' => 'https://10.70.0.1:0'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'port above maximum' => [
            ['gateway' => 'https://10.70.0.1:65536'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'repeated slash path' => [
            ['gateway' => 'https://10.70.0.1//profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'relative CA path' => [
            ['gateway' => 'https://10.70.0.1', '--ca' => 'profile-secret/root.pem'],
            'Gateway CA path must be an absolute path.',
        ],
        'control character in CA path' => [
            ['gateway' => 'https://10.70.0.1', '--ca' => "/tmp/root.pem\nprofile-secret"],
            'Gateway CA path must be an absolute path.',
        ],
    ]);
});

function gateway_add_test_certificate(string $orbitHome): string
{
    mkdir(directory: $orbitHome, permissions: 0o700, recursive: true);
    $configPath = $orbitHome.'/openssl.cnf';
    file_put_contents($configPath, <<<'OPENSSL'
        [req]
        distinguished_name = subject
        x509_extensions = v3_ca
        prompt = no

        [subject]
        CN = Orbit Gateway Root CA

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
            ['commonName' => 'Orbit Gateway Root CA'],
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
