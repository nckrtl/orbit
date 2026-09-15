<?php

declare(strict_types=1);

use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RuntimeHibernatorUnitRenderer;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

function requireProof(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$runner = new NativeProcessRunner;
function command(array $argv): string
{
    global $runner;
    $result = $runner->run(new ProcessInvocation($argv));
    requireProof($result->succeeded(), json_encode(['argv' => $argv, 'exit' => $result->exitCode, 'stderr' => $result->stderr], JSON_THROW_ON_ERROR));
    return trim($result->stdout);
}

requireProof(str_contains(command(['hostname']), 'orbit-e2e-orb-364-'), 'Refusing a host outside ORB-364 disposable topology.');
requireProof(command(['id', '-un']) === 'orbit', 'Proof must run as ordinary orbit user.');
$version = command(['install', '--version']);
requireProof(str_contains($version, 'uutils coreutils) 0.8.0'), 'Expected Ubuntu uutils 0.8.0.');
$root = '/home/orbit/orbit/apps/gateway';
$units = new RuntimeHibernatorUnitRenderer;
$paths = [$units->servicePath(), $units->timerPath()];
$identity = is_file('/home/orbit/orbit/.git')
    ? file_get_contents('/var/lib/orbit-e2e/source-state')
    : command(['git', '-C', '/home/orbit/orbit', 'rev-parse', 'HEAD']);
echo json_encode(['launcher' => PHP_BINARY, 'fixture' => __FILE__, 'candidate' => $identity, 'hostname' => command(['hostname']), 'install' => $version, 'os' => file_get_contents('/etc/os-release')], JSON_THROW_ON_ERROR).PHP_EOL;

function convergeAndInspect(string $label, string $user, int $seconds): void
{
    global $runner, $root, $units, $paths;
    $converger = new NativeRuntimeHibernatorConverger(processes: $runner, phpBinary: PHP_BINARY, artisan: $root.'/artisan', orbitHome: '/home/orbit/.orbit', workingDirectory: $root, user: $user, sweepSeconds: $seconds);
    $converger->converge();
    $expected = [$units->renderService(PHP_BINARY, $root.'/artisan', '/home/orbit/.orbit', $root, $user), $units->renderTimer($seconds)];
    $files = [];
    foreach ($paths as $index => $path) {
        $contents = file_get_contents($path);
        requireProof($contents === $expected[$index], 'Installed contents mismatch: '.$path);
        $metadata = command(['stat', '-c', '%U:%G:%a', $path]);
        requireProof($metadata === 'root:root:644', 'Ownership/mode mismatch: '.$metadata);
        $files[] = ['path' => $path, 'metadata' => $metadata, 'sha256' => hash('sha256', $contents), 'contents' => $contents];
    }
    requireProof(command(['systemctl', 'is-enabled', $units->timerName()]) === 'enabled', 'Timer is not enabled.');
    requireProof(command(['systemctl', 'is-active', $units->timerName()]) === 'active', 'Timer is not active.');
    echo json_encode(['phase' => $label, 'files' => $files, 'timer' => 'enabled and active'], JSON_THROW_ON_ERROR).PHP_EOL;
}

try {
    command(['sudo', 'systemctl', 'disable', '--now', $units->timerName()]);
    command(['sudo', 'systemctl', 'stop', $units->serviceName()]);
    command(['sudo', 'rm', '--', ...$paths]);
    clearstatcache();
    foreach ($paths as $path) {
        requireProof(! file_exists($path), 'Initial installation destination still exists.');
    }
    convergeAndInspect('initial', 'orbit', 600);
    convergeAndInspect('repeated', 'orbit', 600);
    command(['id', 'nobody']);
    convergeAndInspect('changed', 'nobody', 601);
} finally {
    convergeAndInspect('restored', 'orbit', 600);
}
echo "ORB-364 repeatable installation: PASS\n";
