<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TestToolchain;

/**
 * A Node root for the update script: the agent binary and unit, and shims for `curl`, `systemctl`, `chown`, and
 * `sleep`. `curl` copies the file named in `download`; `systemctl show` reports the restart counts listed in
 * `restarts`, one per call, and every call is logged in `systemctl.log`.
 */
final class AgentUpdateHost
{
    public readonly string $root;

    public function __construct(string $installed = 'agent 0.3.0', string $download = 'agent 0.4.0')
    {
        $this->root = sys_get_temp_dir().'/orbit-agent-update-'.Str::uuid();
        mkdir($this->root.'/bin', 0o700, true);
        file_put_contents($this->root.'/orbit-agent', $installed);
        chmod($this->root.'/orbit-agent', 0o700);
        file_put_contents($this->root.'/orbit-agent.service', NodeAgentFootprint::Marker."\n[Unit]\n");
        file_put_contents($this->root.'/download', $download);
        file_put_contents($this->root.'/restarts', "0\n");
        $this->shim('curl', <<<'BASH'
            output=
            while [ "$#" -gt 0 ]; do
              if [ "$1" = --output ]; then output=$2; shift; fi
              shift
            done
            cp -- "$ORBIT_TEST_ROOT/download" "$output"
            BASH);
        $this->shim('systemctl', <<<'BASH'
            printf '%s\n' "$*" >> "$ORBIT_TEST_ROOT/systemctl.log"
            if [ "$1" = show ]; then
              count=$(head -n 1 "$ORBIT_TEST_ROOT/restarts")
              if [ "$(wc -l < "$ORBIT_TEST_ROOT/restarts")" -gt 1 ]; then sed -i 1d "$ORBIT_TEST_ROOT/restarts"; fi
              printf 'ActiveState=active\nNRestarts=%s\n' "$count"
            fi
            BASH);
        $this->shim('chown', 'exit 0');
        $this->shim('sleep', 'exit 0');
    }

    /** @return array{int, string} */
    public function run(?string $expected = null): array
    {
        $process = new Process([TestToolchain::bash(), '-seu', '--',
            $this->root.'/orbit-agent', $this->root.'/orbit-agent.service', NodeAgentFootprint::Marker,
            'https://github.com/nckrtl/orbit/releases/download/agent-v0.4.0/orbit-agent-0.4.0-linux-x86_64',
            $expected ?? hash('sha256', 'agent 0.4.0'), 'orbit-agent.service', '5',
        ], $this->root, ['PATH' => $this->root.'/bin:'.TestToolchain::path(), 'ORBIT_TEST_ROOT' => $this->root]);
        $process->setInput(GatewayNodeAgentUpdate::Script);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput()];
    }

    public function binary(): string
    {
        return (string) file_get_contents($this->root.'/orbit-agent');
    }

    /** @return list<string> */
    public function systemctl(): array
    {
        $log = @file_get_contents($this->root.'/systemctl.log');

        return $log === false ? [] : explode("\n", trim($log));
    }

    public function remove(): void
    {
        new Filesystem()->deleteDirectory($this->root);
    }

    private function shim(string $name, string $body): void
    {
        file_put_contents($this->root.'/bin/'.$name, "#!/usr/bin/env bash\n{$body}\n");
        chmod($this->root.'/bin/'.$name, 0o755);
    }
}

final class AgentUpdateRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(private readonly CommandResult $result) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return $this->result;
    }
}

function agent_update_gateway(string $architecture = 'x86_64', string $platform = 'linux'): Node
{
    return new Node(['name' => 'gateway', 'platform' => $platform, 'architecture' => $architecture]);
}

describe('the Gateway Node agent update script', function (): void {
    beforeEach(function (): void {
        $this->host = new AgentUpdateHost;
    });

    afterEach(function (): void {
        $this->host->remove();
    });

    it('leaves a matching binary and the running agent alone', function (): void {
        [$exit, $output] = $this->host->run(hash('sha256', 'agent 0.3.0'));

        expect($exit)->toBe(0)
            ->and($output)->toBe("unchanged\n")
            ->and($this->host->systemctl())->toBe([])
            ->and(is_file($this->host->root.'/orbit-agent.orbit-candidate'))->toBeFalse();
    });

    it('moves the verified candidate into place, restarts the agent, and watches it for the health window', function (): void {
        [$exit, $output] = $this->host->run();

        expect($exit)->toBe(0)
            ->and($output)->toBe('updated '.hash('sha256', 'agent 0.3.0')."\n")
            ->and($this->host->binary())->toBe('agent 0.4.0')
            ->and(fileperms($this->host->root.'/orbit-agent') & 0o777)->toBe(0o755)
            ->and(file_get_contents($this->host->root.'/orbit-agent.orbit-previous'))->toBe('agent 0.3.0')
            ->and(is_file($this->host->root.'/orbit-agent.orbit-candidate'))->toBeFalse()
            ->and($this->host->systemctl())->toBe([
                'restart orbit-agent.service',
                ...array_fill(0, 1 + GatewayNodeAgentUpdate::HealthSeconds, 'show orbit-agent.service --property=ActiveState --property=NRestarts'),
            ]);
    });

    it('deletes a candidate that fails the checksum and keeps the installed agent running', function (): void {
        file_put_contents($this->host->root.'/download', 'tampered');

        [$exit] = $this->host->run();

        expect($exit)->toBe(21)
            ->and($this->host->binary())->toBe('agent 0.3.0')
            ->and(is_file($this->host->root.'/orbit-agent.orbit-candidate'))->toBeFalse()
            ->and($this->host->systemctl())->toBe([]);
    });

    it('restores the previous binary when the new agent does not stay up', function (): void {
        file_put_contents($this->host->root.'/restarts', "0\n0\n1\n");

        [$exit, $output] = $this->host->run();

        expect($exit)->toBe(24)
            ->and($output)->toEndWith("restored\n")
            ->and($this->host->binary())->toBe('agent 0.3.0')
            ->and(array_values(array_filter($this->host->systemctl(), static fn (string $call): bool => str_starts_with($call, 'restart'))))
            ->toBe(['restart orbit-agent.service', 'restart orbit-agent.service']);
    });

    it('skips a Gateway Node whose agent unit Orbit did not write', function (): void {
        file_put_contents($this->host->root.'/orbit-agent.service', "[Unit]\n");

        [$exit] = $this->host->run();

        expect($exit)->toBe(10)
            ->and($this->host->binary())->toBe('agent 0.3.0')
            ->and($this->host->systemctl())->toBe([]);
    });
});

describe(GatewayNodeAgentUpdate::class, function (): void {
    it('runs the script locally with sudo under the self-update lock, for the pinned asset', function (): void {
        $runner = new AgentUpdateRunner(new CommandResult(0, "unchanged\n", '', 1, false));

        $result = new GatewayNodeAgentUpdate($runner)->converge(agent_update_gateway('aarch64'));

        expect($result)->toBe(['outcome' => 'unchanged', 'version' => NodeAgentFootprint::Version])
            ->and($runner->invocations)->toHaveCount(1)
            ->and($runner->invocations[0]->arguments)->toBe([
                'sudo', 'flock', '-w', '120', '-E', '75', '/run/lock/orbit-self-update.lock',
                'bash', '-seu', '--',
                '/usr/local/bin/orbit-agent', '/etc/systemd/system/orbit-agent.service', '# Managed by Orbit: agent',
                NodeAgentFootprint::downloadUrl('aarch64'), NodeAgentFootprint::checksum('aarch64'),
                'orbit-agent.service', '5',
            ])
            ->and($runner->invocations[0]->input)->toBe(GatewayNodeAgentUpdate::Script);
    });

    it('reports the binary it replaced', function (): void {
        $runner = new AgentUpdateRunner(new CommandResult(0, 'updated '.str_repeat('b', 64)."\n", '', 1, false));

        expect(new GatewayNodeAgentUpdate($runner)->converge(agent_update_gateway()))->toBe([
            'outcome' => 'updated',
            'version' => NodeAgentFootprint::Version,
            'previous_sha256' => str_repeat('b', 64),
        ]);
    });

    it('turns each failure into a recorded result instead of an exception', function (int $exit, string $stdout, string $errorCode, string $message): void {
        $result = new GatewayNodeAgentUpdate(new AgentUpdateRunner(new CommandResult($exit, $stdout, "warning\nsudo: a password is required\n", 1, false)))->converge(agent_update_gateway());

        expect($result)->toMatchArray(['outcome' => 'failed', 'version' => NodeAgentFootprint::Version, 'error_code' => $errorCode])
            ->and($result['message'])->toContain($message);
    })->with([
        'download' => [20, '', 'agent.binary_download_failed', 'could not be downloaded'],
        'checksum' => [21, '', 'agent.checksum_mismatch', 'checksum'],
        'move' => [22, '', 'agent.install_failed', 'moved into place'],
        'restart' => [23, "updated none\n", 'agent.restart_failed', 'No previous orbit-agent was restored'],
        'health' => [24, "updated abc\nrestored\n", 'agent.unhealthy', 'The previous orbit-agent is restored and running'],
        'health, restore stopped' => [24, "updated abc\nrestored_stopped\n", 'agent.unhealthy', 'systemctl could not restart it'],
        'busy lock' => [75, '', 'node.update_busy', '120 seconds'],
        'unexpected' => [1, '', 'agent.install_failed', 'exited with code 1: sudo: a password is required'],
    ]);

    it('skips a Gateway Node without an Orbit agent unit, or not on Linux, without failing', function (): void {
        $runner = new AgentUpdateRunner(new CommandResult(10, '', '', 1, false));
        $update = new GatewayNodeAgentUpdate($runner);

        expect($update->converge(agent_update_gateway()))->toBe(['outcome' => 'skipped', 'reason' => 'not_installed'])
            ->and($update->converge(agent_update_gateway(platform: 'darwin')))->toBe(['outcome' => 'skipped', 'reason' => 'platform'])
            ->and($runner->invocations)->toHaveCount(1);
    });

    it('fails without running anything for an architecture the pin has no asset for', function (): void {
        $runner = new AgentUpdateRunner(new CommandResult(0, '', '', 1, false));

        expect(new GatewayNodeAgentUpdate($runner)->converge(agent_update_gateway('riscv64')))->toMatchArray(['outcome' => 'failed', 'error_code' => 'agent.architecture_unsupported'])
            ->and($runner->invocations)->toBe([]);
    });
});
