<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-cli-dns-'.Str::uuid();
    $this->originalHome = getenv('HOME');
    putenv("HOME={$this->root}");
    $this->resolverDirectory = $this->root.'/etc/resolver';
    $this->configurationDirectory = $this->root.'/config/orbit/dnsmasq.d';
    $this->masterConfigurationPath = $this->root.'/homebrew/etc/dnsmasq.conf';
    $this->launchAgentsDirectory = $this->root.'/Library/LaunchAgents';
    $this->brewExecutable = '/opt/homebrew/bin/brew';
});

afterEach(function (): void {
    is_string($this->originalHome)
        ? putenv("HOME={$this->originalHome}")
        : putenv('HOME');
    new Filesystem()->deleteDirectory($this->root);
});

it('binds the local resolver implementation', function (): void {
    expect(app(ResolvesLocalDns::class))->toBeInstanceOf(LocalResolver::class);
});

it('routes a wildcard TLD through local dnsmasq', function (): void {
    new Filesystem()->ensureDirectoryExists($this->launchAgentsDirectory);
    file_put_contents(
        filename: $this->launchAgentsDirectory.'/homebrew.mxcl.dnsmasq.plist',
        data: 'stale',
    );
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;

        if ($command === ['dig', '@127.0.0.1', 'orbit-local-resolver-health.beast', '+short']) {
            return Process::result(output: "192.168.6.20\n");
        }

        if (is_array($command) && array_slice(array: $command, offset: 0, length: 3) === ['sudo', '-n', 'install']) {
            new Filesystem()->ensureDirectoryExists(dirname($command[11]));
            copy($command[10], $command[11]);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('beast', '192.168.6.20', 'tld');

    expect($result)
        ->toBe(['status' => 'resolved', 'changed' => true])
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n")
        ->and(file_get_contents($this->masterConfigurationPath))
        ->toBe("conf-dir={$this->configurationDirectory}/,*.conf\n")
        ->and(file_get_contents($this->resolverDirectory.'/beast'))
        ->toBe("nameserver 127.0.0.1\n")
        ->and(file_exists($this->launchAgentsDirectory.'/homebrew.mxcl.dnsmasq.plist'))
        ->toBeFalse();
    Process::assertRan(
        fn (PendingProcess $process): bool => (
            $process->command === [
                'launchctl',
                'bootout',
                'gui/'.posix_geteuid().'/homebrew.mxcl.dnsmasq',
            ]
        ),
    );
    Process::assertRan(
        fn (PendingProcess $process): bool => (
            $process->command === [
                'sudo',
                '-n',
                $this->brewExecutable,
                'services',
                'restart',
                'dnsmasq',
            ]
        ),
    );
    Process::assertRan(
        fn (PendingProcess $process): bool => $process->command === ['sudo', '-v'],
    );
    Process::assertRan(
        fn (PendingProcess $process): bool => (
            $process->command === [
                'dig',
                '@127.0.0.1',
                'orbit-local-resolver-health.beast',
                '+short',
            ]
        ),
    );
});

it('preserves operator dnsmasq configuration while updating an override', function (): void {
    new Filesystem()->ensureDirectoryExists(dirname($this->masterConfigurationPath));
    file_put_contents(
        filename: $this->masterConfigurationPath,
        data: "server=1.1.1.1\naddress=/beast/10.44.0.7\nconf-dir=/old/.config/orbit/dnsmasq.d/,*.conf\n",
    );
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['dig', '@127.0.0.1', 'orbit-local-resolver-health.beast', '+short']) {
            return Process::result(output: "192.168.6.20\n");
        }

        if (
            is_array($process->command)
            && array_slice(array: $process->command, offset: 0, length: 3) === ['sudo', '-n', 'install']
        ) {
            new Filesystem()->ensureDirectoryExists(dirname($process->command[11]));
            copy($process->command[10], $process->command[11]);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $resolver->resolve('beast', '192.168.6.20', 'tld');

    expect(file_get_contents($this->masterConfigurationPath))
        ->toBe(
            "server=1.1.1.1\nconf-dir={$this->configurationDirectory}/,*.conf\n",
        );
});

it('does not rewrite a healthy existing local override', function (): void {
    new Filesystem()->ensureDirectoryExists($this->configurationDirectory);
    new Filesystem()->ensureDirectoryExists($this->resolverDirectory);
    new Filesystem()->ensureDirectoryExists(dirname($this->masterConfigurationPath));
    file_put_contents(
        filename: $this->configurationDirectory.'/beast.conf',
        data: "address=/beast/192.168.6.20\n",
    );
    file_put_contents(filename: $this->resolverDirectory.'/beast', data: "nameserver 127.0.0.1\n");
    file_put_contents(
        $this->masterConfigurationPath,
        "conf-dir={$this->configurationDirectory}/,*.conf\n",
    );
    Process::fake(
        fn (PendingProcess $process) => $process->command === [
            'dig',
            '@127.0.0.1',
            'orbit-local-resolver-health.beast',
            '+short',
        ]
                ? Process::result(output: "192.168.6.20\n")
                : Process::result(),
    );
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('beast', '192.168.6.20', 'tld');

    expect($result)->toBe(['status' => 'already_resolved', 'changed' => false]);
    Process::assertNotRan(
        fn (PendingProcess $process): bool => is_array($process->command) && ($process->command[0] ?? null) === 'sudo',
    );
});

it('removes only the selected local override', function (): void {
    new Filesystem()->ensureDirectoryExists($this->configurationDirectory);
    new Filesystem()->ensureDirectoryExists($this->resolverDirectory);
    file_put_contents(
        filename: $this->configurationDirectory.'/beast.conf',
        data: "address=/beast/192.168.6.20\n",
    );
    file_put_contents(
        filename: $this->configurationDirectory.'/mini.conf',
        data: "address=/mini/192.168.1.30\n",
    );
    file_put_contents(filename: $this->resolverDirectory.'/beast', data: "nameserver 127.0.0.1\n");
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;

        if (is_array($command) && array_slice(array: $command, offset: 0, length: 4) === ['sudo', '-n', 'rm', '--']) {
            unlink($command[4]);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->reset('beast');

    expect($result)
        ->toBe(['status' => 'reset', 'changed' => true])
        ->and(file_exists($this->configurationDirectory.'/beast.conf'))
        ->toBeFalse()
        ->and(file_get_contents($this->configurationDirectory.'/mini.conf'))
        ->toBe("address=/mini/192.168.1.30\n")
        ->and(file_exists($this->resolverDirectory.'/beast'))
        ->toBeFalse();
    Process::assertRan(
        fn (PendingProcess $process): bool => (
            $process->command === [
                'sudo',
                '-n',
                $this->brewExecutable,
                'services',
                'restart',
                'dnsmasq',
            ]
        ),
    );
});

it('installs an exact Route name beside a wildcard TLD override', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    fake_local_resolver_processes([
        'shop.app.beast' => "192.168.1.40\n",
    ]);
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('shop.app.beast', '192.168.1.40', 'hostname');

    expect($result)
        ->toBe(['status' => 'resolved', 'changed' => true])
        ->and(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBe("host-record=shop.app.beast,192.168.1.40\n")
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n")
        ->and(file_get_contents($this->resolverDirectory.'/shop.app.beast'))
        ->toBe("nameserver 127.0.0.1\n")
        ->and(file_get_contents($this->resolverDirectory.'/beast'))
        ->toBe("nameserver 127.0.0.1\n");
});

it('points an exact Route name at the Cluster Router or the workload', function (string $target): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    fake_local_resolver_processes([
        'shop.app.beast' => "{$target}\n",
    ]);
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('shop.app.beast', $target, 'hostname');

    expect($result)
        ->toBe(['status' => 'resolved', 'changed' => true])
        ->and(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBe("host-record=shop.app.beast,{$target}\n")
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n");
})->with([
    'router' => ['192.168.6.20'],
    'workload' => ['192.168.1.40'],
]);

it('gives an exact hostname override precedence over the wildcard TLD', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    fake_local_resolver_processes([
        'shop.app.beast' => "192.168.1.40\n",
    ]);
    $resolver = local_resolver_for_test($this);

    $resolver->resolve('shop.app.beast', '192.168.1.40', 'hostname');

    expect(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBe("host-record=shop.app.beast,192.168.1.40\n")
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n")
        ->and(file_exists($this->resolverDirectory.'/shop.app.beast'))
        ->toBeTrue()
        ->and(file_exists($this->resolverDirectory.'/beast'))
        ->toBeTrue();
});

it('resets an exact hostname override without changing the wildcard TLD', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    new Filesystem()->ensureDirectoryExists($this->configurationDirectory);
    new Filesystem()->ensureDirectoryExists($this->resolverDirectory);
    file_put_contents(
        filename: $this->configurationDirectory.'/shop.app.beast.conf',
        data: "host-record=shop.app.beast,192.168.1.40\n",
    );
    file_put_contents(
        filename: $this->resolverDirectory.'/shop.app.beast',
        data: "nameserver 127.0.0.1\n",
    );
    fake_local_resolver_processes();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->reset('shop.app.beast');

    expect($result)
        ->toBe(['status' => 'reset', 'changed' => true])
        ->and(file_exists($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBeFalse()
        ->and(file_exists($this->resolverDirectory.'/shop.app.beast'))
        ->toBeFalse()
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n")
        ->and(file_get_contents($this->resolverDirectory.'/beast'))
        ->toBe("nameserver 127.0.0.1\n");
});

it('does not rewrite a healthy existing exact hostname override', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    new Filesystem()->ensureDirectoryExists($this->configurationDirectory);
    new Filesystem()->ensureDirectoryExists($this->resolverDirectory);
    file_put_contents(
        filename: $this->configurationDirectory.'/shop.app.beast.conf',
        data: "host-record=shop.app.beast,192.168.1.40\n",
    );
    file_put_contents(
        filename: $this->resolverDirectory.'/shop.app.beast',
        data: "nameserver 127.0.0.1\n",
    );
    Process::fake(
        fn (PendingProcess $process) => $process->command === [
            'dig',
            '@127.0.0.1',
            'shop.app.beast',
            '+short',
        ]
            ? Process::result(output: "192.168.1.40\n")
            : Process::result(),
    );
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('shop.app.beast', '192.168.1.40', 'hostname');

    expect($result)->toBe(['status' => 'already_resolved', 'changed' => false]);
    Process::assertNotRan(
        fn (PendingProcess $process): bool => is_array($process->command) && ($process->command[0] ?? null) === 'sudo',
    );
    expect(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n");
});

it('leaves unrelated overrides unchanged when a write is refused', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    $master = file_get_contents($this->masterConfigurationPath);
    Process::fake(fn (): mixed => Process::result(exitCode: 1));
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('shop.app.beast', '192.168.1.40', 'hostname');

    expect($result)
        ->toBe(['status' => 'write_failed', 'changed' => false])
        ->and(file_exists($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBeFalse()
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n")
        ->and(file_get_contents($this->masterConfigurationPath))
        ->toBe($master);
});

describe('exact hostname mapping migration', function (): void {
    it('replaces legacy and duplicate hostname directives at the same target', function (string $mapping): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        file_put_contents($this->configurationDirectory.'/shop.app.beast.conf', $mapping);
        file_put_contents($this->resolverDirectory.'/shop.app.beast', "nameserver 127.0.0.1\n");
        file_put_contents($this->configurationDirectory.'/other.app.beast.conf', "host-record=other.app.beast,192.168.1.41\n");
        fake_local_resolver_processes([
            'shop.app.beast' => "192.168.1.40\n",
            'orbit-local-resolver-health.shop.app.beast' => "192.168.1.40\n",
        ]);

        $result = local_resolver_for_test($this)->resolve('shop.app.beast', '192.168.1.40', 'hostname');

        expect($result)->toBe(['status' => 'resolved', 'changed' => true])
            ->and(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
            ->toBe("host-record=shop.app.beast,192.168.1.40\n")
            ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
            ->toBe("address=/beast/192.168.6.20\n")
            ->and(file_get_contents($this->configurationDirectory.'/other.app.beast.conf'))
            ->toBe("host-record=other.app.beast,192.168.1.41\n");
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['dig', '@127.0.0.1', 'shop.app.beast', '+short']);
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === ['dig', '@127.0.0.1', 'orbit-local-resolver-health.shop.app.beast', '+short']);
    })->with([
        'legacy wildcard' => ["address=/shop.app.beast/192.168.1.40\n"],
        'legacy dotted wildcard' => ["address=/.shop.app.beast/192.168.1.40\n"],
        'exact plus legacy wildcard' => ["host-record=shop.app.beast,192.168.1.40\naddress=/shop.app.beast/192.168.1.40\n"],
        'duplicate exact records' => ["host-record=shop.app.beast,192.168.1.40\nhost-record=shop.app.beast,192.168.1.42\n"],
    ]);

    it('removes only matching legacy master directives while keeping other mappings', function (): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        $unrelated = "server=127.0.0.2\naddress=/beast/192.168.6.20\naddress=/child.shop.app.beast/192.168.1.42\nhost-record=other.app.beast,192.168.1.41\n";
        file_put_contents($this->masterConfigurationPath, $unrelated."address=/shop.app.beast/192.168.1.40\naddress=/.shop.app.beast/192.168.1.40\nconf-dir=/old/.config/orbit/dnsmasq.d/,*.conf\n");
        fake_local_resolver_processes(['shop.app.beast' => "192.168.1.40\n"]);

        $result = local_resolver_for_test($this)->resolve('shop.app.beast', '192.168.1.40', 'hostname');

        expect($result)->toBe(['status' => 'resolved', 'changed' => true])
            ->and(file_get_contents($this->masterConfigurationPath))
            ->toBe($unrelated."conf-dir={$this->configurationDirectory}/,*.conf\n");
    });

    it('keeps exact hostname records when resetting their wildcard TLD', function (): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        file_put_contents($this->configurationDirectory.'/shop.app.beast.conf', "host-record=shop.app.beast,192.168.1.40\n");
        file_put_contents($this->resolverDirectory.'/shop.app.beast', "nameserver 127.0.0.1\n");
        fake_local_resolver_processes();

        $result = local_resolver_for_test($this)->reset('beast');

        expect($result)->toBe(['status' => 'reset', 'changed' => true])
            ->and(file_exists($this->configurationDirectory.'/beast.conf'))->toBeFalse()
            ->and(file_exists($this->resolverDirectory.'/beast'))->toBeFalse()
            ->and(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
            ->toBe("host-record=shop.app.beast,192.168.1.40\n")
            ->and(file_get_contents($this->resolverDirectory.'/shop.app.beast'))
            ->toBe("nameserver 127.0.0.1\n");
    });
});

it('refuses an unsafe resolver name before writing files', function (): void {
    seed_wildcard_override($this, 'beast', '192.168.6.20');
    Process::fake();
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('../etc/passwd', '192.168.1.40', 'hostname');

    expect($result)
        ->toBe(['status' => 'write_failed', 'changed' => false])
        ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))
        ->toBe("address=/beast/192.168.6.20\n");
});

it('installs an IPv6 exact hostname override', function (): void {
    fake_local_resolver_processes([
        'shop.app.beast' => "2001:db8::8\n",
        'AAAA' => true,
    ]);
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('shop.app.beast', '2001:db8::8', 'hostname');

    expect($result)
        ->toBe(['status' => 'resolved', 'changed' => true])
        ->and(file_get_contents($this->configurationDirectory.'/shop.app.beast.conf'))
        ->toBe("host-record=shop.app.beast,2001:db8::8\n");
    Process::assertRan(
        fn (PendingProcess $process): bool => $process->command === [
            'dig',
            '@127.0.0.1',
            'shop.app.beast',
            'AAAA',
            '+short',
        ],
    );
});

it('reports a local dnsmasq refresh failure', function (): void {
    Process::fake(function (PendingProcess $process) {
        if (
            $process->command === [
                'sudo',
                '-n',
                $this->brewExecutable,
                'services',
                'restart',
                'dnsmasq',
            ]
        ) {
            return Process::result(exitCode: 1);
        }

        if (
            is_array($process->command)
            && array_slice(array: $process->command, offset: 0, length: 3) === ['sudo', '-n', 'install']
        ) {
            new Filesystem()->ensureDirectoryExists(dirname($process->command[11]));
            copy($process->command[10], $process->command[11]);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
    $resolver = local_resolver_for_test($this);

    $result = $resolver->resolve('beast', '192.168.6.20', 'tld');

    expect($result)->toBe(['status' => 'refresh_failed', 'changed' => true]);
});

describe('confirmed resolver reset changes', function (): void {
    it('stops before system resolver removal when native mapping unlink is refused', function (): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        $mapping = $this->configurationDirectory.'/shop.app.beast.conf';
        mkdir($mapping);
        file_put_contents($mapping.'/keep', 'native directory cannot be unlinked as a file');
        file_put_contents($this->resolverDirectory.'/shop.app.beast', "nameserver 127.0.0.1\n");
        fake_local_resolver_processes();

        $result = local_resolver_for_test($this)->reset('shop.app.beast');

        expect($result)->toBe(['status' => 'write_failed', 'changed' => false])
            ->and(file_get_contents($mapping.'/keep'))->toBe('native directory cannot be unlinked as a file')
            ->and(file_get_contents($this->resolverDirectory.'/shop.app.beast'))->toBe("nameserver 127.0.0.1\n")
            ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))->toBe("address=/beast/192.168.6.20\n");
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['sudo', '-v']);
        Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
    });

    it('preserves completed mapping removal after a later system resolver failure and supports retry', function (bool $throws): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        file_put_contents($this->configurationDirectory.'/shop.app.beast.conf', "host-record=shop.app.beast,192.168.1.40\n");
        file_put_contents($this->resolverDirectory.'/shop.app.beast', "nameserver 127.0.0.1\n");
        $remove = ['sudo', '-n', 'rm', '--', $this->resolverDirectory.'/shop.app.beast'];
        Process::fake(function (PendingProcess $process) use ($remove, $throws) {
            if ($process->command === $remove) {
                return $throws ? throw new RuntimeException('private native error') : Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();
        $resolver = local_resolver_for_test($this);

        $result = $resolver->reset('shop.app.beast');

        expect($result)->toBe(['status' => 'write_failed', 'changed' => true])
            ->and(file_exists($this->configurationDirectory.'/shop.app.beast.conf'))->toBeFalse()
            ->and(file_get_contents($this->resolverDirectory.'/shop.app.beast'))->toBe("nameserver 127.0.0.1\n")
            ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))->toBe("address=/beast/192.168.6.20\n");
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === ['sudo', '-n', $this->brewExecutable, 'services', 'restart', 'dnsmasq']);
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === ['dscacheutil', '-flushcache']);

        fake_local_resolver_processes();

        $retry = $resolver->reset('shop.app.beast');

        expect($retry)->toBe(['status' => 'reset', 'changed' => true])
            ->and(file_exists($this->configurationDirectory.'/shop.app.beast.conf'))->toBeFalse()
            ->and(file_exists($this->resolverDirectory.'/shop.app.beast'))->toBeFalse()
            ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))->toBe("address=/beast/192.168.6.20\n");
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === $remove);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['sudo', '-n', $this->brewExecutable, 'services', 'restart', 'dnsmasq']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['dscacheutil', '-flushcache']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['sudo', '-n', 'killall', '-HUP', 'mDNSResponder']);

    })->with(['failed removal' => false, 'thrown removal' => true]);

    it('leaves absent mappings unchanged without authorizing or refreshing', function (): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        fake_local_resolver_processes();

        $result = local_resolver_for_test($this)->reset('shop.app.beast');

        expect($result)->toBe(['status' => 'already_absent', 'changed' => false])
            ->and(file_get_contents($this->configurationDirectory.'/beast.conf'))->toBe("address=/beast/192.168.6.20\n");
        Process::assertNothingRan();
    });

    it('reports no completed change when only the system resolver exists and removal fails', function (): void {
        new Filesystem()->ensureDirectoryExists($this->resolverDirectory);
        file_put_contents($this->resolverDirectory.'/shop.app.beast', "nameserver 127.0.0.1\n");
        Process::fake(fn (PendingProcess $process) => Process::result(exitCode: $process->command === ['sudo', '-v'] ? 0 : 1));
        Process::preventStrayProcesses();

        $result = local_resolver_for_test($this)->reset('shop.app.beast');

        expect($result)->toBe(['status' => 'write_failed', 'changed' => false])
            ->and(file_get_contents($this->resolverDirectory.'/shop.app.beast'))->toBe("nameserver 127.0.0.1\n");
        Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
    });

    it('retains confirmed removals but does not flush caches when refresh fails', function (): void {
        seed_wildcard_override($this, 'beast', '192.168.6.20');
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sudo', '-n', 'rm', '--', $this->resolverDirectory.'/beast']) {
                unlink($this->resolverDirectory.'/beast');
            }

            return Process::result(exitCode: $process->command === ['sudo', '-n', $this->brewExecutable, 'services', 'restart', 'dnsmasq'] ? 1 : 0);
        });
        Process::preventStrayProcesses();

        $result = local_resolver_for_test($this)->reset('beast');

        expect($result)->toBe(['status' => 'refresh_failed', 'changed' => true])
            ->and(file_exists($this->configurationDirectory.'/beast.conf'))->toBeFalse()
            ->and(file_exists($this->resolverDirectory.'/beast'))->toBeFalse();
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === ['dscacheutil', '-flushcache']);
    });
});

function local_resolver_for_test(object $test): LocalResolver
{
    return new LocalResolver(
        platform: 'macos',
        resolverDirectory: $test->resolverDirectory,
        configurationDirectory: $test->configurationDirectory,
        masterConfigurationPath: $test->masterConfigurationPath,
        brewExecutable: $test->brewExecutable,
    );
}

function seed_wildcard_override(object $test, string $tld, string $target): void
{
    new Filesystem()->ensureDirectoryExists($test->configurationDirectory);
    new Filesystem()->ensureDirectoryExists($test->resolverDirectory);
    new Filesystem()->ensureDirectoryExists(dirname($test->masterConfigurationPath));
    file_put_contents(
        filename: $test->configurationDirectory."/{$tld}.conf",
        data: "address=/{$tld}/{$target}\n",
    );
    file_put_contents(filename: $test->resolverDirectory.'/'.$tld, data: "nameserver 127.0.0.1\n");
    file_put_contents(
        $test->masterConfigurationPath,
        "conf-dir={$test->configurationDirectory}/,*.conf\n",
    );
}

/**
 * @param  array<string, string|true>  $answers
 */
function fake_local_resolver_processes(array $answers = []): void
{
    $ipv6 = ($answers['AAAA'] ?? false) === true;
    unset($answers['AAAA']);

    Process::fake(function (PendingProcess $process) use ($answers, $ipv6) {
        $command = $process->command;

        if (is_array($command) && ($command[0] ?? null) === 'dig') {
            $name = $command[2] ?? null;
            $hasAaaa = in_array('AAAA', $command, strict: true);

            if ($ipv6 !== $hasAaaa) {
                return Process::result(output: '');
            }

            if (is_string($name) && array_key_exists($name, $answers)) {
                return Process::result(output: $answers[$name]);
            }
        }

        if (is_array($command) && array_slice(array: $command, offset: 0, length: 3) === ['sudo', '-n', 'install']) {
            new Filesystem()->ensureDirectoryExists(dirname($command[11]));
            copy($command[10], $command[11]);
        }

        if (is_array($command) && array_slice(array: $command, offset: 0, length: 4) === ['sudo', '-n', 'rm', '--']) {
            unlink($command[4]);
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
}
