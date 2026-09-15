<?php

declare(strict_types=1);

use App\Services\Dns\ResolvesLocalDns;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->resolver = new class implements ResolvesLocalDns
    {
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
        public function resolve(string $name, string $target): array
        {
            $this->resolveCalls[] = ['tld' => $name, 'target' => $target];

            return $this->resolveResult;
        }

        /** @return array{status: string, changed: bool} */
        public function reset(string $name): array
        {
            $this->resetCalls[] = $name;

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

it('preserves wildcard human output', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'beast',
            'target' => '10.44.0.7',
        ])
        ->expectsOutputToContain('.beast resolves locally to 10.44.0.7.')
        ->expectsOutputToContain('Restart open browsers to use the new route.')
        ->assertExitCode(0);
});

it('configures an exact private Route resolver override', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            'target' => '10.44.0.8',
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'hostname' => 'shop.app.beast',
            'target' => '10.44.0.8',
            'status' => 'resolved',
            'changed' => true,
            'restart_browser' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($this->resolver->resolveCalls)->toBe([
        ['tld' => 'shop.app.beast', 'target' => '10.44.0.8'],
    ]);
});

it('accepts an IPv6 target for an exact Route name', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            'target' => '2001:db8::8',
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'hostname' => 'shop.app.beast',
            'target' => '2001:db8::8',
            'status' => 'resolved',
            'changed' => true,
            'restart_browser' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('names the exact hostname in human output', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            'target' => '10.44.0.8',
        ])
        ->expectsOutputToContain('shop.app.beast resolves locally to 10.44.0.8.')
        ->assertExitCode(0);
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
    'uppercase exact name' => [['tld' => 'Shop.app.beast', 'target' => '10.44.0.8'], 'dns.hostname_invalid'],
    'empty exact label' => [['tld' => 'shop..beast', 'target' => '10.44.0.8'], 'dns.hostname_invalid'],
    'leading-dot exact name' => [['tld' => '.shop.app.beast', 'target' => '10.44.0.8'], 'dns.hostname_invalid'],
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

it('removes an exact private Route resolver override', function (): void {
    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            '--reset' => true,
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'hostname' => 'shop.app.beast',
            'target' => null,
            'status' => 'reset',
            'changed' => true,
            'restart_browser' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($this->resolver->resetCalls)->toBe(['shop.app.beast']);
});

it('repeats an identical exact-name install without mutation', function (): void {
    $this->resolver->resolveResult = ['status' => 'already_resolved', 'changed' => false];

    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            'target' => '10.44.0.8',
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'hostname' => 'shop.app.beast',
            'target' => '10.44.0.8',
            'status' => 'already_resolved',
            'changed' => false,
            'restart_browser' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('repeats an identical exact-name reset without mutation', function (): void {
    $this->resolver->resetResult = ['status' => 'already_absent', 'changed' => false];

    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            '--reset' => true,
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'hostname' => 'shop.app.beast',
            'target' => null,
            'status' => 'already_absent',
            'changed' => false,
            'restart_browser' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
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

it('reports a local resolver write failure', function (): void {
    $this->resolver->resolveResult = ['status' => 'write_failed', 'changed' => false];

    $this
        ->artisan('dns:resolve', [
            'tld' => 'shop.app.beast',
            'target' => '10.44.0.8',
            '--json' => true,
        ])
        ->expectsOutputToContain('"code":"dns.write_failed"')
        ->assertExitCode(1);
});
