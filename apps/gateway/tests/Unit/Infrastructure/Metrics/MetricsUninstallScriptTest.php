<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsFootprint;
use App\Infrastructure\Metrics\MetricsUninstallScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;

it('renders syntactically valid bash', function (): void {
    $result = new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: ['bash', '-n', metricsUninstallScriptPath()],
    ));

    expect($result->exitCode)->toBe(0);
});

it('names every generated configuration path the script removes', function (): void {
    $rendered = metricsUninstallScriptRendered();

    foreach (MetricsFootprint::ConfigurationPaths as $path) {
        expect($rendered)->toContain($path);
    }
});

it('names every configuration directory the script walks in reverse', function (): void {
    $rendered = metricsUninstallScriptRendered();

    foreach (MetricsFootprint::ConfigurationDirectories as $directory) {
        expect($rendered)->toContain($directory);
    }
});

it('names the configuration ownership marker', function (): void {
    expect(metricsUninstallScriptRendered())->toContain(MetricsFootprint::OwnershipMarker);
});

it('names the exporter drop-in', function (): void {
    expect(metricsUninstallScriptRendered())->toContain(MetricsFootprint::ExporterDropIn);
});

it('names the exporter drop-in ownership marker', function (): void {
    expect(metricsUninstallScriptRendered())->toContain(MetricsFootprint::ExporterDropInMarker);
});

it('names the exporter firewall comment', function (): void {
    expect(metricsUninstallScriptRendered())->toContain(MetricsFootprint::ExporterFirewallComment);
});

it('names the publication firewall comment', function (): void {
    expect(metricsUninstallScriptRendered())->toContain(MetricsFootprint::PublicationFirewallComment);
});

it('filters Docker resources on the exact managed label', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain(MetricsFootprint::ManagedLabel.'='.MetricsFootprint::ManagedValue);
});

it('never issues an apt-get command, only reports the manual purge', function (): void {
    $rendered = metricsUninstallScriptRendered();

    $commandLines = array_values(array_filter(
        explode("\n", $rendered),
        static fn (string $line): bool => preg_match('/^\s*apt-get(\s|$)/', $line) === 1,
    ));

    expect($commandLines)->toBe([]);
});

it('reports the exact manual purge command for the exporter package', function (): void {
    expect(metricsUninstallScriptRendered())->toContain('sudo apt-get purge --yes ${PACKAGE}');
});

it('refuses to run as a non-root user and prints nothing to stdout', function (): void {
    metricsUninstallSkipIfRoot($this);

    $result = metricsUninstallScriptRun();

    expect($result->exitCode)->toBe(4)->and($result->stdout)->toBe('');
});

it('shows usage and exits successfully for --help regardless of user', function (): void {
    $result = metricsUninstallScriptRun('--help');

    expect($result->exitCode)->toBe(0)->and($result->stdout)->toContain('Usage: sudo');
});

it('checks the root guard before acting on --dry-run', function (): void {
    metricsUninstallSkipIfRoot($this);

    $result = metricsUninstallScriptRun('--dry-run');

    expect($result->exitCode)->toBe(4)->and($result->stdout)->toBe('');
});

it('never pipes ufw output into another command', function (): void {
    $rendered = metricsUninstallScriptRendered();

    $codeLines = array_values(array_filter(
        explode("\n", $rendered),
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    ));

    $piped = array_values(array_filter(
        $codeLines,
        static fn (string $line): bool => preg_match('/\bufw\b.*[^|]\|[^|]/', $line) === 1,
    ));

    expect($piped)->toBe([]);
});

it('binds the exporter port the firewall shape proof compares', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain("readonly EXPORTER_PORT='".MetricsFootprint::ExporterPort."'");
});

it('binds the publication port the firewall shape proof compares', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain("readonly GRAFANA_PORT='".MetricsFootprint::PublicationPort."'");
});

it('binds the WireGuard interface the firewall shape proof compares', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain("readonly INTERFACE='".MetricsFootprint::WireGuardInterface."'");
});

it('proves a firewall rule by its shape, not only by its comment', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain('local expected="${node_address} ${port}/tcp on ${INTERFACE}"');
});

it('requires a single IPv4 source, so an Anywhere rule is drift', function (): void {
    expect(metricsUninstallScriptRendered())
        ->toContain('[[ "${source}" =~ ^[0-9]{1,3}(\\.[0-9]{1,3}){3}$ ]] || return 1');
});

it('previews resources under a "Would remove:" heading on --dry-run', function (): void {
    expect(metricsUninstallScriptRendered())->toContain('Would remove:');
});

it('names resources under a "Will remove:" heading when actually removing', function (): void {
    expect(metricsUninstallScriptRendered())->toContain('Will remove:');
});

it('promises in --help exactly what --force and --dry-run do', function (): void {
    $result = metricsUninstallScriptRun('--help');

    expect($result->stdout)->toContain('do not ask for confirmation');
    expect($result->stdout)->toContain('report what would be removed and change nothing');
});

function metricsUninstallScriptRendered(): string
{
    static $rendered = null;

    return $rendered ??= new MetricsUninstallScript()->render();
}

function metricsUninstallScriptPath(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $created = tempnam(sys_get_temp_dir(), 'orbit-metrics-uninstall-');

    if ($created === false) {
        throw new RuntimeException('Could not create a temporary uninstall script.');
    }

    file_put_contents($created, metricsUninstallScriptRendered());
    chmod($created, 0755);

    register_shutdown_function(static function () use ($created): void {
        if (file_exists($created)) {
            unlink($created);
        }
    });

    return $path = $created;
}

function metricsUninstallScriptRun(string ...$arguments): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: ['bash', metricsUninstallScriptPath(), ...$arguments],
    ));
}

/**
 * Skips the calling test when this process is root.
 *
 * The script's root guard is the first thing `main` checks, and CI never
 * runs the suite as root, but a developer's shell sometimes does. Skipping
 * is honest about that instead of asserting a guard the environment cannot
 * exercise.
 */
function metricsUninstallSkipIfRoot(PHPUnit\Framework\TestCase $test): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $test->markTestSkipped('This process runs as root; the non-root guard cannot be exercised.');
    }
}
