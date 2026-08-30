<?php

declare(strict_types=1);

use App\Services\Dns\ResolvesLocalDns;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->resolver = new class implements ResolvesLocalDns {
        public string $platform = 'macos';

        public bool $available = true;

        /** @var list<array{tld: string, target: string}> */
        public array $resolveCalls = [];

        /** @var list<string> */
        public array $resetCalls = [];

        /** @var array{status: string, changed: bool} */
        public array $resolveResult = ['status' => 'resolved', 'changed' => true];

        /** @var array{status: string, changed: bool} */
        public array $resetResult = ['status' => 'reset', 'changed' => true];

        public function platform(): string
        {
            return $this->platform;
        }

        public function available(): bool
        {
            return $this->available;
        }

        /** @return array{status: string, changed: bool} */
        public function resolve(string $tld, string $target): array
        {
            $this->resolveCalls[] = ['tld' => $tld, 'target' => $target];

            return $this->resolveResult;
        }

        /** @return array{status: string, changed: bool} */
        public function reset(string $tld): array
        {
            $this->resetCalls[] = $tld;

            return $this->resetResult;
        }
    };
    app()->instance(ResolvesLocalDns::class, $this->resolver);
    Process::fake();
    Process::preventStrayProcesses();
});

it('configures a caller-local TLD resolver override', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'tld' => 'beast',
            'target' => '10.44.0.7',
            'status' => 'resolved',
            'changed' => true,
            'restart_browser' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($this->resolver->resolveCalls)->toBe([
        ['tld' => 'beast', 'target' => '10.44.0.7'],
    ]);
});

it('rejects invalid input without changing resolver state', function (array $arguments, string $code): void {
    $this
        ->artisan('dns:resolve', [...$arguments, '--json' => true])
        ->expectsOutputToContain('"code":"'.$code.'"')
        ->assertExitCode(1);

    expect($this->resolver->resolveCalls)
        ->toBeEmpty()
        ->and($this->resolver->resetCalls)
        ->toBeEmpty();
})->with([
    'leading dot' => [['tld' => '.beast', 'target' => '10.44.0.7'], 'dns.tld_invalid'],
    'hostname target' => [['tld' => 'beast', 'target' => 'gateway.orbit'], 'dns.target_invalid'],
]);

it('rejects a target together with reset', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
            '--reset' => true,
            '--json' => true,
        ])
        ->expectsOutputToContain('"code":"dns.target_invalid"')
        ->assertExitCode(1);

    expect($this->resolver->resetCalls)->toBeEmpty();
});

it('removes a caller-local TLD resolver override', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            '--reset' => true,
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'tld' => 'beast',
            'target' => null,
            'status' => 'reset',
            'changed' => true,
            'restart_browser' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($this->resolver->resetCalls)->toBe(['beast']);
});

it('rejects unsupported platforms', function (): void {
    $platform = 'linux';
    $this->resolver->platform = $platform;

    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
            '--json' => true,
        ])
        ->expectsOutputToContain('"code":"dns.unsupported_platform"')
        ->assertExitCode(1);

    expect($this->resolver->resolveCalls)->toBeEmpty();
});

it('reports a missing local dnsmasq installation', function (): void {
    $this->resolver->available = false;

    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
            '--json' => true,
        ])
        ->expectsOutputToContain('"code":"dns.dnsmasq_missing"')
        ->assertExitCode(1);

    expect($this->resolver->resolveCalls)->toBeEmpty();
});

it('reports a local dnsmasq refresh failure', function (): void {
    $this->resolver->resolveResult = ['status' => 'refresh_failed', 'changed' => true];

    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
            '--json' => true,
        ])
        ->expectsOutputToContain('"code":"dns.refresh_failed"')
        ->assertExitCode(1);
});
