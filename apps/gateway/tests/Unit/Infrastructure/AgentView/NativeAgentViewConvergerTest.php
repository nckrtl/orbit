<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentView\AgentViewUnitRenderer;
use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final class AgentViewPublicationProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public string $unit = '';

    public function __construct(private readonly ?string $failing = null) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->commands[] = $invocation->arguments;

        if (array_slice($invocation->arguments, 0, 2) === ['sudo', 'install']) {
            $this->unit = (string) file_get_contents($invocation->arguments[4]);
        }

        return in_array($this->failing, $invocation->arguments, strict: true)
            ? new CommandResult(1, '', 'failed', 1, false)
            : new CommandResult(0, '', '', 1, false);
    }
}

describe(NativeAgentViewConverger::class, function (): void {
    it('installs, enables, and restarts the subscriber as the Gateway account', function (): void {
        $processes = new AgentViewPublicationProcessRunner;
        $units = new AgentViewUnitRenderer;

        new NativeAgentViewConverger(
            processes: $processes,
            phpBinary: '/usr/bin/php8.5',
            artisan: '/home/orbit/orbit/apps/gateway/artisan',
            orbitHome: '/home/orbit/.orbit',
            workingDirectory: '/home/orbit/orbit/apps/gateway',
        )->converge();

        expect(array_slice($processes->commands, 1))->toBe([
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'enable', 'orbit-agent-view.service'],
            ['sudo', 'systemctl', 'restart', 'orbit-agent-view.service'],
        ])
            ->and($processes->commands[0][5])->toBe($units->path())
            ->and($processes->unit)
            ->toContain('ExecStart="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "orbit:agent-view"')
            ->toContain('User=orbit')
            ->toContain('Restart=always')
            ->toContain('RestartSec=2')
            ->toContain('Environment=ORBIT_HOME=/home/orbit/.orbit');
    });

    it('fails with a stable code before restarting when enabling fails', function (): void {
        $processes = new AgentViewPublicationProcessRunner(failing: 'enable');

        expect(fn () => new NativeAgentViewConverger(
            processes: $processes,
            artisan: '/home/orbit/orbit/apps/gateway/artisan',
            orbitHome: '/home/orbit/.orbit',
            workingDirectory: '/home/orbit/orbit/apps/gateway',
        )->converge())->toThrow(
            fn (NodeProvisioningException $exception) => expect($exception->errorCode)->toBe('gateway.agent_view_install_failed')
                ->and($exception->step)->toBe('gateway-agent-view-enable'),
        );

        expect($processes->commands)->toHaveCount(3);
    });
});
