<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayCaddyConverger;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Gateway\NativeGatewayWebConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @mago-expect lint:halstead The interaction test keeps each security-sensitive publication boundary observable. */
it('publishes complete validated FPM Caddy and certificate configurations through atomic switches', function (): void {
    [$converger, $processes, $issuer, $orbitHome] = gateway_web_converger();

    try {
        $converger->converge('gateway.orbit', '10.44.0.1');
        $calls = Collection::make($processes->calls);
        $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
        $fpmValidationIndex = $commands->search(
            static fn (array $arguments): bool => $arguments === [
                'sudo',
                'php-fpm8.5',
                '--test',
                '--fpm-config',
                '/etc/php/8.5/fpm/orbit-gateway-candidate.conf',
            ],
        );
        $fpmPublishIndex = $commands->search(
            static fn (array $arguments): bool => $arguments === [
                'sudo',
                'mv',
                '-f',
                '--',
                '/etc/php/8.5/fpm/orbit-candidate.d/orbit-gateway.conf',
                '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf',
            ],
        );
        $caddyValidationIndex = $commands->search(
            static fn (array $arguments): bool => (
                $arguments[0] === 'sudo'
                && $arguments[1] === 'caddy'
                && $arguments[2] === 'validate'
                && str_ends_with($arguments[4] ?? '', '/Caddyfile')
                && str_contains($arguments[4], '/etc/caddy/orbit-versions/')
            ),
        );
        $caddyPublishIndex = $commands->search(
            static fn (array $arguments): bool => (
                array_slice(array: $arguments, offset: 0, length: 4) === ['sudo', 'mv', '-Tf', '--']
                && end($arguments) === '/etc/caddy/Caddyfile'
            ),
        );
        $certificatePublish = $commands->first(
            static fn (array $arguments): bool => (
                array_slice(array: $arguments, offset: 0, length: 4) === ['sudo', 'mv', '-Tf', '--']
                && end($arguments) === '/etc/caddy/orbit-cert-current'
            ),
        );
        $fpmStage = $calls->first(
            static fn (ProcessInvocation $invocation): bool => (
                $invocation->arguments[0] === 'sudo'
                && $invocation->arguments[1] === 'bash'
                && str_contains($invocation->input ?? '', 'orbit-candidate.d')
            ),
        );
        $caddyStage = $calls->first(
            static fn (ProcessInvocation $invocation): bool => (
                $invocation->arguments[0] === 'sudo'
                && $invocation->arguments[1] === 'bash'
                && str_contains($invocation->input ?? '', 'orbit-versions')
            ),
        );

        expect($issuer->calls)
            ->toBe([['hostname' => 'gateway.orbit', 'address' => '10.44.0.1']])
            ->and(file_get_contents($orbitHome.'/generated/gateway/php-fpm-pool.conf'))
            ->toContain(
                '[orbit-gateway]',
                'listen = /run/php/orbit-gateway.sock',
                'listen.group = caddy',
                'listen.mode = 0660',
                'request_terminate_timeout = 900s',
                'php_admin_value[max_execution_time] = 900',
                'php_admin_value[opcache.validate_timestamps] = 1',
            )
            ->and(file_get_contents($orbitHome.'/generated/gateway/Caddyfile'))
            ->toContain(
                'gateway.orbit, 10.44.0.1',
                'bind 10.44.0.1',
                'root * /home/orbit/orbit-gateway/public',
                'tls /etc/caddy/orbit-cert-current/gateway.pem /etc/caddy/orbit-cert-current/gateway.key',
                'php_fastcgi unix//run/php/orbit-gateway.sock',
                'dial_timeout 10s',
                'read_timeout 900s',
                'write_timeout 900s',
            )
            ->and(fileperms($orbitHome.'/generated/gateway/php-fpm-pool.conf') & 0o777)
            ->toBe(0o644)
            ->and(fileperms($orbitHome.'/generated/gateway/Caddyfile') & 0o777)
            ->toBe(0o644)
            ->and(fileperms($orbitHome.'/generated/gateway') & 0o777)
            ->toBe(0o700)
            ->and($fpmStage?->input)
            ->toContain(
                'for pool in /etc/php/8.5/fpm/pool.d/*.conf',
                'if [ "$pool_name" = orbit-gateway.conf ]; then',
                'cp --preserve=mode,ownership -- "$pool" "$candidate_directory/$pool_name"',
                'replacement_count',
            )
            ->and($fpmValidationIndex)
            ->toBeInt()
            ->toBeLessThan($fpmPublishIndex)
            ->and($caddyStage?->input)
            ->toContain(
                'source_main=$(readlink -f /etc/caddy/Caddyfile)',
                'previous_fragments=$(dirname "$source_main")/fragments',
                'orbit-versions',
                'printf \'import %s/fragments/*.caddy\\n\' "$version_directory" > "$candidate_directory/Caddyfile"',
            )
            ->not
            ->toContain('"$source_main" > "$candidate_directory/Caddyfile"')
            ->and($caddyValidationIndex)
            ->toBeInt()
            ->toBeLessThan($caddyPublishIndex)
            ->and($certificatePublish)
            ->toBeArray()
            ->and($commands->contains(
                static fn (array $arguments): bool => (
                    in_array(needle: '/etc/caddy/orbit-cert-current/gateway.pem', haystack: $arguments, strict: true)
                    || in_array(needle: '/etc/caddy/orbit-cert-current/gateway.key', haystack: $arguments, strict: true)
                ),
            ))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'chmod', '0710', '/home/orbit', '/home/orbit/orbit-gateway']))
            ->toBeTrue()
            ->and($commands->contains(['sudo', 'chmod', '0600', '/home/orbit/orbit-gateway/.env']))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('publishes one complete gateway file from distinct candidates under contention', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $markerDirectory = $directory.'/markers';
    $startPath = $markerDirectory.'/start';
    $characters = ['A', 'B', 'C', 'D'];
    $bytes = 16 * 1024 * 1024;
    $processes = [];
    $observedCandidates = [];

    mkdir($markerDirectory, permissions: 0o700, recursive: true);

    try {
        foreach ($characters as $index => $character) {
            $readyPath = "{$markerDirectory}/ready-{$index}";
            $process = protected_file_writer_process($path, $character, $bytes, $readyPath, $startPath);
            $process->start();
            $processes[] = $process;
        }

        foreach (array_keys($characters) as $index) {
            protected_file_writer_wait_until(static fn (): bool => is_file("{$markerDirectory}/ready-{$index}"));
        }

        file_put_contents($startPath, 'start');
        protected_file_writer_wait_until(function () use ($path, &$observedCandidates): bool {
            $observedCandidates = array_values(array_unique([
                ...$observedCandidates,
                ...protected_file_writer_candidates($path),
            ]));

            return count($observedCandidates) >= 2;
        });

        foreach ($processes as $process) {
            protected_file_writer_wait_for_success($process);
        }

        $contents = file_get_contents($path);

        expect(is_string($contents))->toBeTrue();

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The contended protected file was not published.');
        }

        expect(count($observedCandidates))->toBeGreaterThanOrEqual(2);
        expect(in_array($contents[0], $characters, true))->toBeTrue();
        expect($contents)->toBe(str_repeat($contents[0], $bytes));
        expect(protected_file_writer_candidates($path))->toBe([]);
        expect(fileperms($path) & 0o777)->toBe(0o644);
        expect(fileperms(dirname($path)) & 0o777)->toBe(0o700);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }

        new Filesystem()->deleteDirectory($directory);
    }
});

it('preserves a refused destination and cleans only its protected-file candidate', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $unrelatedCandidate = $path.'.candidate.other-invocation';
    mkdir($path, permissions: 0o700, recursive: true);
    file_put_contents($path.'/original', 'original');
    file_put_contents($unrelatedCandidate, 'other');
    chmod($unrelatedCandidate, 0o600);

    try {
        set_error_handler(static fn (): bool => true);

        try {
            expect(fn () => new ProtectedFileWriter()->put($path, 'replacement', 0o644))
                ->toThrow(RuntimeException::class, "Could not install protected file [{$path}].");
        } finally {
            restore_error_handler();
        }

        expect(file_get_contents($path.'/original'))->toBe('original');
        expect(protected_file_writer_siblings($path))->toBe([$unrelatedCandidate]);
        expect(file_get_contents($unrelatedCandidate))->toBe('other');
        expect(fileperms($unrelatedCandidate) & 0o777)->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('adapts Caddy to a listener bound only to the gateway WireGuard address', function (): void {
    $configuration = new GatewayCaddyConfigRenderer()->render(
        hostname: 'gateway.orbit',
        wireguardIp: '10.44.0.1',
        checkoutPath: '/home/orbit/orbit-gateway',
    );
    $result = caddy_adapt($configuration);
    /** @var array{apps: array{http: array{servers: array<string, array{listen: list<string>}>}}} $adapted */
    $adapted = json_decode(json: $result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
    $listeners = Collection::make($adapted['apps']['http']['servers'])
        ->flatMap(static fn (array $server): array => $server['listen'])
        ->values()
        ->all();

    expect($result->succeeded())
        ->toBeTrue()
        ->and($listeners)
        ->toBe(['10.44.0.1:443'])
        ->not->toContain(':443', '0.0.0.0:443');
});

it('preserves live FPM disk and does not reload when complete effective validation fails', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'fpm-validation');

    try {
        expect(fn () => $converger->converge('gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-fpm-validate')
                    ->and($exception->errorCode)
                    ->toBe('gateway.fpm_config_invalid');
            });

        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);

        expect($commands->contains([
            'sudo',
            'mv',
            '-f',
            '--',
            '/etc/php/8.5/fpm/orbit-candidate.d/orbit-gateway.conf',
            '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf',
        ]))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('orders the gateway Caddy unit after the managed WireGuard interface', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger();

    try {
        $converger->converge('gateway.orbit', '10.44.0.1');
        $calls = Collection::make($processes->calls);
        $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
        $reloadIndex = $commands->search(['sudo', 'systemctl', 'reload-or-restart', 'caddy']);
        $orderingIndex = $commands->search(
            ['sudo', 'bash', '-seu', '--', 'caddy', '/etc/systemd/system'],
        );
        $ordering = $calls->firstOrFail(
            static fn (ProcessInvocation $invocation): bool => (
                $invocation->arguments === [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    'caddy',
                    '/etc/systemd/system',
                ]
            ),
        );
        $orderingInput = $ordering->input ?? '';

        expect($reloadIndex)
            ->toBeInt()
            ->and($orderingIndex)
            ->toBeInt()
            ->toBeGreaterThan($reloadIndex)
            ->and($orderingInput)
            ->toContain(
                'managed=$directory/orbit-vpn.conf',
                'if [ -f "$managed" ] && cmp -s -- "$staged" "$managed"; then',
                'if systemctl is-active --quiet "$service"; then',
                'mv -fT -- "$candidate" "$managed"',
                'systemctl daemon-reload',
            )
            ->and(base64_decode(
                Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $orderingInput),
                strict: true,
            ))
            ->toBe("# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n");
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('preserves the prior aggregate Caddy configuration when staged final validation fails', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'caddy-validation');

    try {
        expect(fn () => $converger->converge('gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-caddy-validate')
                    ->and($exception->errorCode)
                    ->toBe('gateway.caddy_config_invalid');
            });

        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);

        expect($commands->contains(
            static fn (array $arguments): bool => (
                array_slice(array: $arguments, offset: 0, length: 4) === ['sudo', 'mv', '-Tf', '--']
                && end($arguments) === '/etc/caddy/Caddyfile'
            ),
        ))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'caddy']))
            ->toBeFalse()
            ->and($commands->contains(
                static fn (array $arguments): bool => $arguments[0] === 'sudo'
                && $arguments[1] === 'rm'
                && in_array(needle: '/etc/caddy/Caddyfile', haystack: $arguments, strict: true),
            ))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not disturb the published Caddy certificate pair when its atomic link switch fails', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'certificate-publication');

    try {
        expect(fn () => $converger->converge('gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-certificate-publish')
                    ->and($exception->errorCode)
                    ->toBe('gateway.certificate_publish_failed');
            });

        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);

        expect($commands->contains(
            static fn (array $arguments): bool => $arguments[0] === 'sudo'
            && $arguments[1] === 'rm'
            && in_array(needle: '/etc/caddy/orbit-cert-current', haystack: $arguments, strict: true),
        ))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'caddy']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects a checkout outside the configured Orbit home before certificate or sudo work', function (): void {
    [$converger, $processes, $issuer, $orbitHome] = gateway_web_converger(
        checkoutPath: '/srv/external/orbit-gateway',
    );

    try {
        expect(fn () => $converger->converge('gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-checkout-validate')
                    ->and($exception->errorCode)
                    ->toBe('gateway.checkout_invalid');
            })
            ->and($issuer->calls)
            ->toBeEmpty()
            ->and($processes->calls)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

function protected_file_writer_process(
    string $path,
    string $character,
    int $bytes,
    string $readyPath,
    string $startPath,
): Process {
    $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';

        file_put_contents($argv[4], 'ready');

        while (! is_file($argv[5])) {
            usleep(1_000);
        }

        new App\Infrastructure\Files\ProtectedFileWriter()->put(
            $argv[2],
            str_repeat($argv[3], (int) $argv[6]),
            0o644,
        );
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $script,
        base_path(),
        $path,
        $character,
        $readyPath,
        $startPath,
        (string) $bytes,
    ]);
    $process->setTimeout(30);

    return $process;
}

/** @return list<string> */
function protected_file_writer_candidates(string $path): array
{
    $candidates = glob($path.'.candidate.*');

    if (! is_array($candidates)) {
        return [];
    }

    sort($candidates);

    return $candidates;
}

/** @return list<string> */
function protected_file_writer_siblings(string $path): array
{
    $siblings = glob($path.'.*');

    if (! is_array($siblings)) {
        return [];
    }

    sort($siblings);

    return $siblings;
}

function protected_file_writer_wait_for_success(Process $process): void
{
    $exitCode = $process->wait();

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Protected-file writer failed with exit code {$exitCode}: {$process->getErrorOutput()}",
        );
    }
}

function protected_file_writer_wait_until(Closure $condition, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for protected-file writer state.');
        }

        usleep(1_000);
    }
}

/**
 * @return array{
 *     NativeGatewayWebConverger,
 *     object&ProcessRunner,
 *     object&GatewayCertificateIssuer,
 *     string
 * }
 */
function gateway_web_converger(?string $failure = null, string $checkoutPath = '/home/orbit/orbit-gateway'): array
{
    $orbitHome = sys_get_temp_dir().'/orbit-gateway-web-'.Str::uuid();
    mkdir(directory: $orbitHome.'/ca/gateway-current', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/ca/gateway-current/gateway.key', data: 'PRIVATE KEY');
    file_put_contents(filename: $orbitHome.'/ca/gateway-current/gateway.pem', data: 'CERTIFICATE');
    $issuer = new class($orbitHome) implements GatewayCertificateIssuer {
        /** @var list<array{hostname: string, address: string}> */
        public array $calls = [];

        public function __construct(
            private readonly string $orbitHome,
        ) {}

        public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
        {
            $this->calls[] = ['hostname' => $hostname, 'address' => $wireguardIp];

            return new GatewayCertificatePaths(
                privateKeyPath: $this->orbitHome.'/ca/gateway-current/gateway.key',
                certificatePath: $this->orbitHome.'/ca/gateway-current/gateway.pem',
            );
        }
    };
    $processes = new class($failure) implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(
            private readonly ?string $failure,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $arguments = $invocation->arguments;

            if (
                $this->failure === 'fpm-validation'
                && $arguments === [
                    'sudo',
                    'php-fpm8.5',
                    '--test',
                    '--fpm-config',
                    '/etc/php/8.5/fpm/orbit-gateway-candidate.conf',
                ]
            ) {
                return new CommandResult(1, '', 'duplicate pool listener', 2, false);
            }

            if (
                $this->failure === 'caddy-validation'
                && $arguments[1] === 'caddy'
                && $arguments[2] === 'validate'
                && str_contains($arguments[4] ?? '', '/etc/caddy/orbit-versions/')
            ) {
                return new CommandResult(1, '', 'aggregate route conflict', 2, false);
            }

            if (
                $this->failure === 'certificate-publication'
                && array_slice(array: $arguments, offset: 0, length: 4) === ['sudo', 'mv', '-Tf', '--']
                && end($arguments) === '/etc/caddy/orbit-cert-current'
            ) {
                return new CommandResult(1, '', 'atomic link switch failed', 2, false);
            }

            if ($arguments[1] === 'openssl' && in_array(needle: '-pubout', haystack: $arguments, strict: true)) {
                return new CommandResult(0, "PUBLIC KEY\n", '', 2, false);
            }

            if ($arguments[1] === 'openssl' && in_array(needle: '-pubkey', haystack: $arguments, strict: true)) {
                return new CommandResult(0, "PUBLIC KEY\n", '', 2, false);
            }

            return new CommandResult(0, '', '', 2, false);
        }
    };

    return [
        new NativeGatewayWebConverger(
            certificates: $issuer,
            caddyRenderer: new GatewayCaddyConfigRenderer,
            fpmRenderer: new GatewayFpmConfigRenderer,
            files: new ProtectedFileWriter,
            checkout: new GatewayCheckoutAccessConverger($processes, $checkoutPath),
            certificatePublisher: new NativeGatewayCertificatePublisher($processes, $orbitHome),
            fpm: new NativeGatewayFpmConverger($processes),
            caddy: new NativeGatewayCaddyConverger($processes),
            orbitHome: $orbitHome,
            checkoutPath: $checkoutPath,
        ),
        $processes,
        $issuer,
        $orbitHome,
    ];
}
