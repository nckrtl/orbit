<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Shared\StoredValue;
use RuntimeException;
use Throwable;

/**
 * Installs `orbit-agent-view.service` on the Gateway host, enables it, and restarts it so it runs
 * the checkout's current code. It runs the PHP that PHP-FPM runs, as the private DNS listener does.
 * The unit names the stable Gateway path, so a restart after a release switch runs the new release.
 */
final readonly class NativeAgentViewConverger implements AgentViewConverger
{
    private const string ERROR_CODE = 'gateway.agent_view_install_failed';

    public function __construct(
        private ProcessRunner $processes,
        private AgentViewUnitRenderer $units = new AgentViewUnitRenderer,
        private string $phpBinary = '/usr/bin/php8.5',
        private string $artisan = '',
        private string $orbitHome = '',
        private string $workingDirectory = '',
        private string $user = 'orbit',
    ) {}

    #[\Override]
    public function converge(): void
    {
        $unit = $this->units->render(
            $this->phpBinary,
            $this->artisan !== '' ? $this->artisan : GatewayApplicationPath::resolve().'/artisan',
            $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home')),
            $this->workingDirectory !== '' ? $this->workingDirectory : GatewayApplicationPath::resolve(),
            $this->user !== '' ? $this->user : 'orbit',
        );

        $this->install($unit);
        $this->run('gateway-agent-view-reload', ['sudo', 'systemctl', 'daemon-reload']);
        $this->run('gateway-agent-view-enable', ['sudo', 'systemctl', 'enable', $this->units->name()]);
        $this->run('gateway-agent-view-restart', ['sudo', 'systemctl', 'restart', $this->units->name()]);
    }

    private function install(string $contents): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $metadata = stream_get_meta_data($input->stream());
            $path = $metadata['uri'] ?? null;

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Gateway agent view service file is unavailable.');
            }

            $this->run('gateway-agent-view-service', ['sudo', 'install', '-m', '0644', $path, $this->units->path()]);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'gateway-agent-view-service',
                errorCode: self::ERROR_CODE,
                message: 'Gateway agent view step [gateway-agent-view-service] failed.',
                previous: $exception,
            );
        } finally {
            $input?->close();
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, array $arguments): void
    {
        $result = $this->processes->run(new ProcessInvocation($arguments));

        if ($result->succeeded()) {
            return;
        }

        throw new NodeProvisioningException(
            step: $step,
            errorCode: self::ERROR_CODE,
            message: "Gateway agent view step [{$step}] failed.",
            result: $result,
        );
    }
}
