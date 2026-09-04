<?php

declare(strict_types=1);

use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Tool;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

const GATEWAY_ROOT = '/home/orbit/orbit/apps/gateway';
const ORBIT = '/usr/local/bin/orbit';
const SSH_KEY = '/home/orbit/.orbit/ssh/id_ed25519';
const KNOWN_HOSTS = '/home/orbit/.orbit/ssh/known_hosts';

require GATEWAY_ROOT.'/vendor/autoload.php';

try {
    match ($argv[1] ?? '') {
        'prepare-stranded' => prepareStranded(),
        'remove-config-files' => removeConfigFiles(),
        'retry-stranded' => retryStranded(),
        default => throw new RuntimeException('Expected one known proof mode.'),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, "ORB-111 proof failed: {$exception->getMessage()}\n");
    exit(1);
}

/** @return never */
function fail(string $message): void
{
    throw new RuntimeException($message);
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        fail($message);
    }
}

/** @param list<string> $arguments */
function run(array $arguments, int|array $expected = 0, int $timeout = 240): Process
{
    $process = new Process($arguments);
    $process->setTimeout($timeout);
    $process->run();

    $expectedCodes = is_array($expected) ? $expected : [$expected];
    if (! in_array($process->getExitCode(), $expectedCodes, true)) {
        $command = implode(' ', array_map(escapeshellarg(...), $arguments));
        fail("Command exited {$process->getExitCode()}: {$command}\n{$process->getErrorOutput()}{$process->getOutput()}");
    }

    return $process;
}

/** @param list<string> $arguments
 *  @return array<string, mixed>
 */
function jsonCommand(array $arguments, int $expected = 0): array
{
    $process = run($arguments, $expected);
    $decoded = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    check(is_array($decoded), 'Command did not return a JSON object.');

    return $decoded;
}

/** @return array<string, mixed> */
function node(string $name): array
{
    $response = jsonCommand([ORBIT, 'node:list', '--json']);
    foreach ($response['nodes'] ?? [] as $node) {
        if (is_array($node) && ($node['name'] ?? null) === $name) {
            return $node;
        }
    }

    fail("Node {$name} was not registered.");
}

/** @param array<string, mixed> $node
 *  @param non-empty-list<string> $arguments
 */
function remote(array $node, array $arguments, int|array $expected = 0): Process
{
    foreach (['user', 'wireguard_ip', 'public_ssh_port'] as $field) {
        check(isset($node[$field]), "Node is missing {$field}.");
    }

    return run([
        'ssh',
        '-i',
        SSH_KEY,
        '-p',
        (string) $node['public_ssh_port'],
        '-o',
        'BatchMode=yes',
        '-o',
        'StrictHostKeyChecking=yes',
        '-o',
        'UserKnownHostsFile='.KNOWN_HOSTS,
        '-o',
        'ConnectTimeout=10',
        '-o',
        'ServerAliveInterval=5',
        '-o',
        'ServerAliveCountMax=2',
        '--',
        "{$node['user']}@{$node['wireguard_ip']}",
        (new RemoteCommand($arguments))->shellCommand(),
    ], $expected);
}

/** @param array<string, mixed> $node
 *  @return array{status:string,version:string}|null
 */
function packageState(array $node, string $package): ?array
{
    $process = remote(
        $node,
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', $package],
        [0, 1],
    );

    if ($process->getExitCode() === 1) {
        check($process->getOutput() === '', "Absent package {$package} returned stdout.");
        check(
            $process->getErrorOutput() === "dpkg-query: no packages found matching {$package}\n",
            "Absent package {$package} returned unexpected stderr.",
        );

        return null;
    }

    $lines = explode("\n", rtrim($process->getOutput(), "\n"));
    check(count($lines) === 2, "Package {$package} returned an unexpected dpkg record.");
    check($process->getErrorOutput() === '', "Package {$package} returned dpkg stderr.");

    return ['status' => $lines[0], 'version' => $lines[1]];
}

/** @param array<string, mixed> $node */
function assertInitiallyAbsent(array $node, string $package): void
{
    check(packageState($node, $package) === null, "Package {$package} was not initially absent.");
    check(findTool((int) $node['id'], $package) === null, "Tool {$package} already existed.");
}

/** @return array<string, mixed> */
function installTool(array $node, string $package): array
{
    $tool = jsonCommand([
        ORBIT,
        'tool:install',
        $package,
        '--node='.(string) $node['id'],
        '--manager=apt',
        '--json',
    ]);
    check(($tool['node_id'] ?? null) === $node['id'], 'Installed Tool has the wrong Node.');
    check(($tool['manager'] ?? null) === 'apt', 'Installed Tool has the wrong manager.');
    check(($tool['package'] ?? null) === $package, 'Installed Tool has the wrong package.');
    check(($tool['status'] ?? null) === 'installed', 'Tool install did not finish installed.');
    check(is_int($tool['id'] ?? null), 'Installed Tool has no numeric ID.');

    return $tool;
}

/** @return array<string, mixed>|null */
function findTool(int $nodeId, string $package): ?array
{
    $response = jsonCommand([ORBIT, 'tool:list', '--node='.(string) $nodeId, '--json']);
    $matching = array_values(array_filter(
        $response['tools'] ?? [],
        fn (mixed $tool): bool => is_array($tool) && ($tool['package'] ?? null) === $package,
    ));
    check(count($matching) <= 1, "More than one Tool row exists for {$package}.");

    return $matching[0] ?? null;
}

/** @return array<string, mixed> */
function removeTool(int $toolId): array
{
    return jsonCommand([ORBIT, 'tool:remove', (string) $toolId, '--json']);
}

/** @param array<string, mixed> $node */
function assertRemovedRecord(array $node, string $package): string
{
    $state = packageState($node, $package);
    check($state !== null, "Package {$package} did not retain a dpkg record.");
    check($state['status'] === 'deinstall ok config-files', "Package {$package} did not retain configuration files.");
    check($state['version'] !== '', "Package {$package} did not retain a version.");

    return $state['version'];
}

/** @param array<string, mixed> $node */
function doctor(array $node, int $expectedExit): array
{
    return jsonCommand([
        ORBIT,
        'doctor',
        '--node='.(string) $node['id'],
        '--family=tool',
        '--json',
    ], $expectedExit);
}

/** @param array<string, mixed> $report */
function toolFamily(array $report): array
{
    $families = $report['nodes'][0]['families'] ?? null;
    check(is_array($families) && count($families) === 1, 'Doctor did not return one Tool family.');
    check(($families[0]['family'] ?? null) === 'tool', 'Doctor returned the wrong family.');

    return $families[0];
}

/** @param array<string, mixed> $node */
function purgeFixture(array $node, string $package): void
{
    remote($node, ['sudo', 'apt-get', 'purge', '--yes', '--', $package]);
    check(packageState($node, $package) === null, "Package {$package} fixture residue remains.");
}

function bootstrapGateway(): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    $app = require GATEWAY_ROOT.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $bootstrapped = true;
}

function prepareStranded(): void
{
    $node = node('app-dev');
    $package = 'supervisor';
    assertInitiallyAbsent($node, $package);
    remote($node, ['test', '!', '-e', '/var/log/supervisor']);
    $installed = installTool($node, $package);
    $state = packageState($node, $package);
    check(($state['status'] ?? null) === 'install ok installed', 'Supervisor was not installed.');

    remote($node, ['sudo', 'apt-get', 'remove', '--yes', '--', $package]);
    $retainedVersion = assertRemovedRecord($node, $package);

    bootstrapGateway();
    $tool = Tool::query()->find($installed['id']);
    check($tool instanceof Tool, 'The Tool row was missing before it could be stranded.');
    $tool->update([
        'status' => ToolStatus::Failed,
        'failed_operation' => ToolOperation::Remove,
        'error_code' => 'tool.version_probe_failed',
    ]);

    $stranded = findTool((int) $node['id'], $package);
    check(($stranded['status'] ?? null) === 'failed', 'The stranded Tool is not failed.');
    check(($stranded['failed_operation'] ?? null) === 'remove', 'The stranded Tool has the wrong failed operation.');
    check(($stranded['error_code'] ?? null) === 'tool.version_probe_failed', 'The stranded Tool has the wrong error code.');

    $report = doctor($node, 1);
    $family = toolFamily($report);
    check(($report['healthy'] ?? null) === false, 'Doctor did not report stranded intent as unhealthy.');
    check(($family['status'] ?? null) === 'drift', 'Doctor did not report stranded intent as drift.');
    check(($family['issues'][0]['code'] ?? null) === 'tool.not_installed', 'Doctor returned the wrong stranded finding.');
    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    check(! str_contains($encoded, 'deinstall ok config-files'), 'Doctor exposed raw dpkg status.');
    check(! str_contains($encoded, $retainedVersion), 'Doctor exposed the retained package version.');

    fwrite(STDOUT, "prepared one bounded stranded Tool and verified tool.not_installed drift\n");
}

function removeConfigFiles(): void
{
    $node = node('app-prod');
    $package = 'rsync';
    assertInitiallyAbsent($node, $package);
    $installed = installTool($node, $package);
    check(($installed['installed_version'] ?? null) !== null, 'Rsync install did not record a version.');

    $removed = removeTool((int) $installed['id']);
    check(($removed['outcome'] ?? null) === 'applied', 'Tool removal was not applied.');
    check(findTool((int) $node['id'], $package) === null, 'Successful removal retained the Tool row.');
    $retainedVersion = assertRemovedRecord($node, $package);

    $report = doctor($node, 0);
    $family = toolFamily($report);
    check(($report['healthy'] ?? null) === true, 'Doctor did not report healthy after removal.');
    check(($family['status'] ?? null) === 'healthy', 'Doctor Tool family was not healthy.');
    check(($family['checked'] ?? null) === 0, 'Doctor inspected deleted Tool intent.');
    check(($family['issues'] ?? null) === [], 'Doctor returned a Tool finding after deletion.');
    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    check(! str_contains($encoded, 'deinstall ok config-files'), 'Doctor exposed raw dpkg status.');
    check(! str_contains($encoded, $retainedVersion), 'Doctor exposed the retained package version.');

    purgeFixture($node, $package);
    fwrite(STDOUT, "removed rsync, deleted Tool intent, retained configuration during verification, and restored fixture state\n");
}

function retryStranded(): void
{
    $node = node('app-dev');
    $package = 'supervisor';
    $stranded = findTool((int) $node['id'], $package);
    check(is_array($stranded), 'The stranded Tool was not prepared.');
    check(($stranded['status'] ?? null) === 'failed', 'The retry Tool was not failed.');
    check(($stranded['failed_operation'] ?? null) === 'remove', 'The retry Tool had the wrong failed operation.');
    check(($stranded['error_code'] ?? null) === 'tool.version_probe_failed', 'The retry Tool had the wrong error code.');
    assertRemovedRecord($node, $package);

    $removed = removeTool((int) $stranded['id']);
    check(($removed['outcome'] ?? null) === 'applied', 'The stranded retry was not applied.');
    check(findTool((int) $node['id'], $package) === null, 'The stranded retry retained the Tool row.');
    assertRemovedRecord($node, $package);

    $report = doctor($node, 0);
    $family = toolFamily($report);
    check(($report['healthy'] ?? null) === true, 'Doctor did not become healthy after retry.');
    check(($family['status'] ?? null) === 'healthy', 'Doctor Tool family was not healthy after retry.');
    check(($family['checked'] ?? null) === 0, 'Doctor inspected deleted Tool intent after retry.');
    check(($family['issues'] ?? null) === [], 'Doctor returned a Tool finding after retry.');

    purgeFixture($node, $package);
    remote($node, ['sudo', 'rm', '--', '/var/log/supervisor/supervisord.log']);
    remote($node, ['sudo', 'rmdir', '--', '/var/log/supervisor']);
    fwrite(STDOUT, "retried only tool:remove, deleted stranded intent, and restored fixture state\n");
}
