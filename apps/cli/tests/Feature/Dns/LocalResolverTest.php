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

    $result = $resolver->resolve('beast', '192.168.6.20');

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

    $resolver->resolve('beast', '192.168.6.20');

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

    $result = $resolver->resolve('beast', '192.168.6.20');

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

    $result = $resolver->resolve('beast', '192.168.6.20');

    expect($result)->toBe(['status' => 'refresh_failed', 'changed' => true]);
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
