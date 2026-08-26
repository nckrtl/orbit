<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\RootCertificate;
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
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-trust-'.Str::uuid();
    $this->trustStore = $this->orbitHome.'/system-trust';
    config()->set('orbit.home', $this->orbitHome);
    $this->certificate = gateway_trust_test_certificate($this->orbitHome);
    $this->fingerprint = openssl_x509_fingerprint($this->certificate, digest_algo: 'sha256');
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test-gateway',
        url: 'https://10.44.0.1',
    ));
    app()->instance(TrustStoreInstallerResolver::class, gateway_trust_linux_resolver($this->trustStore));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('fetches through the SDK and installs the root CA locally on Linux', function (): void {
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $caPath = $this->orbitHome.'/gateways/test-gateway-7c27512b7c3e/ca/'.$this->fingerprint.'.pem';
    $target = $this->trustStore.'/orbit-gateway-ca-7c27512b7c3eb57c.crt';
    Process::fake(function (PendingProcess $process) use ($target) {
        if (is_array($process->command) && ($process->command[1] ?? null) === 'install') {
            if (! is_dir(dirname($target))) {
                mkdir(directory: dirname($target), permissions: 0o755, recursive: true);
            }

            copy($process->command[5], $target);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $this
        ->artisan('gateway:trust', ['--json' => true])
        ->expectsOutput(json_encode([
            'gateway' => 'test-gateway',
            'status' => 'trusted',
            'sha256' => $this->fingerprint,
            'ca_path' => $caPath,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    $profile = app(GatewayConfigRepository::class)->active();
    $caPath = $profile?->caPath;

    expect($caPath)
        ->toBe($this->orbitHome.'/gateways/test-gateway-7c27512b7c3e/ca/'.$this->fingerprint.'.pem')
        ->and(file_get_contents($caPath))
        ->toBe($this->certificate)
        ->and(fileperms(dirname($caPath)) & 0o777)
        ->toBe(0o700)
        ->and(fileperms($caPath) & 0o777)
        ->toBe(0o600);

    Process::assertRan(
        fn ($process): bool => (
            $process->command === [
                'sudo',
                'install',
                '-m',
                '0644',
                '--',
                $caPath,
                $target,
            ]
        ),
    );
    Process::assertRan(fn ($process): bool => $process->command === ['sudo', 'update-ca-certificates']);

    $requests = $mockClient->getRecordedResponses();

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[0]->getPendingRequest()->config()->get('verify'))
        ->toBeFalse()
        ->and($requests[0]->getPendingRequest()->config()->get('allow_redirects'))
        ->toBeFalse()
        ->and($requests[1]->getPendingRequest()->config()->get('verify'))
        ->toBe($caPath)
        ->and($requests[1]->getPendingRequest()->config()->get('allow_redirects'))
        ->toBeFalse();
});

it('returns already_trusted without sudo when the exact certificate fingerprint matches', function (): void {
    $target = $this->trustStore.'/orbit-gateway-ca-7c27512b7c3eb57c.crt';
    mkdir(directory: dirname($target), permissions: 0o755, recursive: true);
    file_put_contents($target, $this->certificate);
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    $this
        ->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway root CA is already trusted.')
        ->assertExitCode(0);

    Process::assertRan(
        fn ($process): bool => (
            $process->command === [
                'openssl',
                'verify',
                '-CAfile',
                '/etc/ssl/certs/ca-certificates.crt',
                $target,
            ]
        ),
    );
    Process::assertNotRan(
        fn ($process): bool => is_array($process->command) && ($process->command[0] ?? null) === 'sudo',
    );
});

it('does not trust a copied Linux CA until the operating-system bundle verifies it', function (): void {
    $target = $this->trustStore.'/orbit-gateway-ca-7c27512b7c3eb57c.crt';
    $trustBundle = $this->orbitHome.'/system-ca-bundle.crt';
    mkdir(directory: dirname($target), permissions: 0o755, recursive: true);
    file_put_contents($target, $this->certificate);
    Process::fake([
        '*' => Process::result(exitCode: 2),
    ]);
    Process::preventStrayProcesses();

    $trusted = new LinuxTrustStoreInstaller($this->trustStore, $trustBundle)->isTrusted(
        RootCertificate::fromPem($this->certificate),
        'test-gateway',
    );

    expect($trusted)->toBeFalse();
    Process::assertRan(
        fn ($process): bool => (
            $process->command === [
                'openssl',
                'verify',
                '-CAfile',
                $trustBundle,
                $target,
            ]
        ),
    );
});

it('matches the exact macOS keychain certificate when the profile name differs from its common name', function (): void {
    $certificate = RootCertificate::fromPem($this->certificate);
    $profileName = 'production-gateway';
    Process::fake([
        '*' => Process::result(output: $this->certificate),
    ]);
    Process::preventStrayProcesses();

    $trusted = new MacOsTrustStoreInstaller()->isTrusted($certificate, $profileName);

    expect($trusted)->toBeTrue();
    Process::assertRan(
        fn (PendingProcess $process): bool => (
            $process->command === [
                'security',
                'find-certificate',
                '-a',
                '-p',
                '/Library/Keychains/System.keychain',
            ]
            && $process->tty === false
        ),
    );
});

it('does not trust a different macOS keychain certificate with the same label', function (): void {
    $differentCertificate = gateway_trust_test_certificate($this->orbitHome.'/different-root');
    Process::fake([
        '*' => Process::result(output: $differentCertificate),
    ]);
    Process::preventStrayProcesses();

    $trusted = new MacOsTrustStoreInstaller()->isTrusted(
        RootCertificate::fromPem($this->certificate),
        'test-gateway',
    );

    expect($trusted)->toBeFalse();
});

it('installs the macOS root CA through typed sudo arguments with a visible tty', function (): void {
    $certificatePath = $this->orbitHome.'/ca/secret-ca-path.pem';
    $result = Process::result();
    $process = Mockery::mock(PendingProcess::class);
    $process->shouldReceive('tty')->once()->withNoArgs()->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->with([
            'sudo',
            'security',
            'add-trusted-cert',
            '-d',
            '-r',
            'trustRoot',
            '-k',
            '/Library/Keychains/System.keychain',
            $certificatePath,
        ])
        ->andReturn($result);
    Process::shouldReceive('timeout')->once()->with(120)->andReturn($process);

    new MacOsTrustStoreInstaller()->install($certificatePath, 'test-gateway');
});

it('redacts macOS trust command failures from json output', function (): void {
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    app()->instance(TrustStoreInstallerResolver::class, new TrustStoreInstallerResolver('Darwin'));
    $sentinel = 'sudo-password=macos-trust-secret';
    $probeResult = Process::result(exitCode: 1);
    $installResult = Process::result(errorOutput: $sentinel, exitCode: 1);
    $probe = Mockery::mock(PendingProcess::class);
    $probe
        ->shouldReceive('run')
        ->once()
        ->with([
            'security',
            'find-certificate',
            '-a',
            '-p',
            '/Library/Keychains/System.keychain',
        ])
        ->andReturn($probeResult);
    $install = Mockery::mock(PendingProcess::class);
    $install->shouldReceive('tty')->once()->withNoArgs()->andReturnSelf();
    $install
        ->shouldReceive('run')
        ->once()
        ->with(Mockery::on(
            fn (array $command): bool => $command[0] === 'sudo'
            && $command[1] === 'security'
            && $command[2] === 'add-trusted-cert'
            && str_ends_with($command[8] ?? '', '.pem'),
        ))
        ->andReturn($installResult);
    Process::shouldReceive('timeout')->once()->with(30)->andReturn($probe);
    Process::shouldReceive('timeout')->once()->with(120)->andReturn($install);
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.ca_install_failed',
            'message' => 'Could not install the gateway root CA on macOS.',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain($sentinel)
        ->not->toContain($this->orbitHome);
});

it('rejects invalid CA material without logging or installing it', function (): void {
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----",
                'sha256' => 'wrong',
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    $this
        ->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway returned invalid root CA material.')
        ->doesntExpectOutputToContain('PRIVATE KEY')
        ->doesntExpectOutputToContain('secret')
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('reports a bounded local storage error without exposing CA material', function (): void {
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $blockedHome = $this->orbitHome.'/blocked-home';
    file_put_contents($blockedHome, data: 'not-a-directory');
    config()->set('orbit.home', $blockedHome);
    Process::fake();
    Process::preventStrayProcesses();
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.ca_store_failed',
            'message' => 'Could not store the gateway root CA locally.',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('BEGIN CERTIFICATE');

    Process::assertNothingRan();
});

it('preserves the valid bootstrap request ID when a pinned success ID is invalid', function (): void {
    $bootstrapRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $invalidRequestId = 'request-id=success-secret';
    MockClient::global([
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => $bootstrapRequestId],
        ]),
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => $invalidRequestId],
        ]),
    ]);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Process::preventStrayProcesses();
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.ca_install_failed',
            'message' => 'Could not install the gateway root CA on Linux.',
            'request_id' => $bootstrapRequestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain($invalidRequestId);
});

it('keeps an invalid successful CA request ID as the SDK empty string', function (): void {
    $invalidRequestId = 'request-id=success-secret';
    $target = $this->trustStore.'/orbit-gateway-ca-7c27512b7c3eb57c.crt';
    mkdir(directory: dirname($target), permissions: 0o755, recursive: true);
    file_put_contents(filename: $target, data: $this->certificate);
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => $invalidRequestId],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();
    $caPath = $this->orbitHome.'/gateways/test-gateway-7c27512b7c3e/ca/'.$this->fingerprint.'.pem';
    $expected = json_encode([
        'gateway' => 'test-gateway',
        'status' => 'already_trusted',
        'sha256' => $this->fingerprint,
        'ca_path' => $caPath,
        'request_id' => '',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(0);
    expect($output)
        ->toBe($expected)
        ->not->toContain($invalidRequestId);
});

it('renders an invalid CA error header request ID as null', function (): void {
    $invalidRequestId = 'request-id=error-secret';
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'gateway.ca_unavailable',
                    'message' => 'Root CA endpoint unavailable.',
                    'details' => [],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => $invalidRequestId],
        ),
    ]);
    Process::fake();
    Process::preventStrayProcesses();
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.ca_unavailable',
            'message' => 'Gateway root CA request failed.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain($invalidRequestId);
    Process::assertNothingRan();
});

it('constructs a new one-shot root CA client for each CLI retry', function (): void {
    $firstRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $secondRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a845';
    $mock = MockClient::global([
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => $firstRequestId],
        ]),
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => $secondRequestId],
        ]),
    ]);
    $blockedHome = $this->orbitHome.'/retry-blocked-home';
    file_put_contents(filename: $blockedHome, data: 'not-a-directory');
    config()->set('orbit.home', $blockedHome);
    Process::fake();
    Process::preventStrayProcesses();

    foreach ([$firstRequestId, $secondRequestId] as $requestId) {
        $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
        $output = trim(Artisan::output());
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.ca_store_failed',
                'message' => 'Could not store the gateway root CA locally.',
                'request_id' => $requestId,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($exitCode)->toBe(1);
        expect($output)->toBe($expected);
    }

    expect($mock->getRecordedResponses())->toHaveCount(2);
    Process::assertNothingRan();
});

it('fails closed when a pinned gateway CA changes without explicit acceptance', function (): void {
    $oldCertificate = gateway_trust_test_certificate($this->orbitHome.'/old-root');
    $oldPath = gateway_trust_pin_profile($this->orbitHome, $oldCertificate);
    MockClient::global([
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.ca_changed',
            'message' =>
                'The gateway root CA differs from the pinned certificate. '
                    .'Re-run with --accept-ca-change only after you verify the new fingerprint.',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('BEGIN CERTIFICATE');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'gateway.ca_changed',
            'message' =>
                'The gateway root CA differs from the pinned certificate. '
                    .'Re-run with --accept-ca-change only after you verify the new fingerprint.',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ],
    ]);
    expect(app(GatewayConfigRepository::class)->active()?->caPath)
        ->toBe($oldPath)
        ->and(file_get_contents($oldPath))
        ->toBe($oldCertificate);
    Process::assertNothingRan();
});

it('keeps the previous profile and CA path when an accepted replacement cannot be installed', function (): void {
    $oldCertificate = gateway_trust_test_certificate($this->orbitHome.'/old-root');
    $oldPath = gateway_trust_pin_profile($this->orbitHome, $oldCertificate);
    MockClient::global([
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845'],
        ]),
    ]);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Process::preventStrayProcesses();

    $this
        ->artisan('gateway:trust', [
            '--accept-ca-change' => true,
            '--json' => true,
        ])
        ->expectsOutputToContain('Could not install the gateway root CA on Linux.')
        ->doesntExpectOutputToContain('BEGIN CERTIFICATE')
        ->assertExitCode(1);

    expect(app(GatewayConfigRepository::class)->active()?->caPath)
        ->toBe($oldPath)
        ->and(file_get_contents($oldPath))
        ->toBe($oldCertificate);
});

it('does not install or pin a replacement that fails pinned HTTPS verification', function (): void {
    $oldCertificate = gateway_trust_test_certificate($this->orbitHome.'/old-root');
    $oldFingerprint = openssl_x509_fingerprint($oldCertificate, digest_algo: 'sha256');
    $oldPath = gateway_trust_pin_profile($this->orbitHome, $oldCertificate);
    MockClient::global([
        MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
        MockResponse::make([
            'data' => [
                'root_ca' => $oldCertificate,
                'sha256' => $oldFingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845'],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    $this
        ->artisan('gateway:trust', ['--accept-ca-change' => true])
        ->expectsOutputToContain('Gateway root CA changed during pinned HTTPS verification.')
        ->doesntExpectOutputToContain('BEGIN CERTIFICATE')
        ->assertExitCode(1);

    expect(app(GatewayConfigRepository::class)->active()?->caPath)
        ->toBe($oldPath)
        ->and(file_get_contents($oldPath))
        ->toBe($oldCertificate);
    Process::assertNothingRan();
});

it('bounds unexpected local trust-store errors', function (): void {
    MockClient::global([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $this->certificate,
                'sha256' => $this->fingerprint,
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    app()->instance(TrustStoreInstallerResolver::class, new class extends TrustStoreInstallerResolver {
        public function resolve(): \App\Services\Trust\TrustStoreInstaller
        {
            return new class implements \App\Services\Trust\TrustStoreInstaller {
                public function isTrusted(
                    \App\Services\Trust\RootCertificate $certificate,
                    string $label,
                ): bool {
                    throw new RuntimeException('secret operating-system diagnostic');
                }

                public function install(string $certificatePath, string $label): void
                {
                    throw new LogicException('Install must not run after an inspection failure.');
                }
            };
        }
    });

    $this
        ->artisan('gateway:trust')
        ->expectsOutputToContain('Could not inspect or update the local certificate trust store.')
        ->doesntExpectOutputToContain('secret operating-system diagnostic')
        ->assertExitCode(1);
});

it('fails clearly when no gateway profile is active', function (): void {
    $emptyHome = sys_get_temp_dir().'/orbit-cli-trust-empty-'.Str::uuid();
    app()->instance(GatewayConfigRepository::class, new GatewayConfigRepository($emptyHome.'/config.json'));

    try {
        $this
            ->artisan('gateway:trust')
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);
    } finally {
        new Filesystem()->deleteDirectory($emptyHome);
    }
});

it('renders a json failure when no gateway profile is active', function (): void {
    $emptyHome = sys_get_temp_dir().'/orbit-cli-trust-empty-json-'.Str::uuid();
    app()->instance(GatewayConfigRepository::class, new GatewayConfigRepository($emptyHome.'/config.json'));
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.profile_missing',
            'message' => 'No active gateway profile.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    try {
        $exitCode = Artisan::call('gateway:trust', ['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe($expected);
    } finally {
        new Filesystem()->deleteDirectory($emptyHome);
    }
});

it('fails closed when the persisted gateway profile is corrupted', function (): void {
    $brokenHome = sys_get_temp_dir().'/orbit-cli-trust-broken-'.Str::uuid();
    $configPath = $brokenHome.'/config.json';
    mkdir($brokenHome, permissions: 0o700, recursive: true);
    file_put_contents($configPath, json_encode([
        'active_gateway' => 'test-gateway',
        'gateways' => [
            'test-gateway' => [
                'url' => 'http://insecure.example',
                'ca_path' => '/tmp/secret-ca.pem',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    chmod(filename: $configPath, permissions: 0o600);
    config()->set('orbit.home', $brokenHome);
    app()->instance(GatewayConfigRepository::class, new GatewayConfigRepository($configPath));
    $mock = MockClient::global();
    Process::fake();
    Process::preventStrayProcesses();

    $expected = json_encode([
        'error' => [
            'code' => 'gateway.config_invalid',
            'message' => 'Orbit gateway configuration is invalid.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    try {
        $exitCode = Artisan::call('gateway:trust', ['--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('secret-ca.pem');
    } finally {
        new Filesystem()->deleteDirectory($brokenHome);
    }

    Process::assertNothingRan();
    expect($mock->getLastPendingRequest())->toBeNull();
});
function gateway_trust_linux_resolver(string $trustStore): TrustStoreInstallerResolver
{
    return new class($trustStore) extends TrustStoreInstallerResolver {
        public function __construct(
            private readonly string $trustStore,
        ) {}

        public function resolve(): \App\Services\Trust\TrustStoreInstaller
        {
            return new LinuxTrustStoreInstaller($this->trustStore);
        }
    };
}

function gateway_trust_test_certificate(string $orbitHome): string
{
    $directory = $orbitHome.'/fixture';
    mkdir(directory: $directory, permissions: 0o700, recursive: true);
    $key = $directory.'/root.key';
    $certificate = $directory.'/root.pem';
    $keyCommand = new Symfony\Component\Process\Process([
        'openssl',
        'genpkey',
        '-algorithm',
        'ED25519',
        '-out',
        $key,
    ]);
    $keyCommand->mustRun();
    $certificateCommand = new Symfony\Component\Process\Process([
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
        '/CN=Orbit Test Root CA',
    ]);
    $certificateCommand->mustRun();

    return (string) file_get_contents($certificate);
}

function gateway_trust_pin_profile(string $orbitHome, string $certificate): string
{
    $fingerprint = openssl_x509_fingerprint($certificate, digest_algo: 'sha256');
    $path = $orbitHome.'/existing/ca/'.$fingerprint.'.pem';
    mkdir(directory: dirname($path), permissions: 0o700, recursive: true);
    file_put_contents($path, $certificate);
    chmod(filename: $path, permissions: 0o600);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test-gateway',
        url: 'https://10.44.0.1',
        caPath: $path,
    ));

    return $path;
}
