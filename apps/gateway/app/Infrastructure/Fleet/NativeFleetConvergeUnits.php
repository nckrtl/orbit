<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Shared\StoredValue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Installs the fleet units through local `sudo`, like the hibernator and agent-view units. */
final readonly class NativeFleetConvergeUnits implements FleetConvergeUnits
{
    /** How long after the request the next run starts, so the running one has exited by then. */
    public const int RestartDelaySeconds = 15;

    public function __construct(
        private ProcessRunner $processes,
        private FleetConvergeUnitRenderer $units = new FleetConvergeUnitRenderer,
        private string $phpBinary = PHP_BINARY,
        private string $artisan = '',
        private string $orbitHome = '',
        private string $workingDirectory = '',
        private string $user = 'orbit',
    ) {}

    public function converge(): void
    {
        $artisan = $this->artisan !== '' ? $this->artisan : GatewayApplicationPath::resolve().'/artisan';
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home'));
        $workingDirectory = $this->workingDirectory !== '' ? $this->workingDirectory : GatewayApplicationPath::resolve();

        $this->install($this->units->servicePath(), $this->units->renderService($this->phpBinary, $artisan, $orbitHome, $workingDirectory, $this->user), 'gateway-fleet-service');
        $this->install($this->units->timerPath(), $this->units->renderTimer(), 'gateway-fleet-timer');
        $this->run('gateway-fleet-reload', ['sudo', 'systemctl', 'daemon-reload']);
        $this->run('gateway-fleet-enable', ['sudo', 'systemctl', 'enable', '--now', $this->units->timerName()]);
    }

    public function start(): bool
    {
        try {
            $result = $this->processes->run(new ProcessInvocation(['sudo', 'systemctl', 'start', '--no-block', $this->units->serviceName()]));
        } catch (Throwable $exception) {
            Log::warning('The fleet rollout unit could not be started.', ['error' => $exception->getMessage()]);

            return false;
        }

        if (! $result->succeeded()) {
            Log::warning('The fleet rollout unit could not be started.', ['exit_code' => $result->exitCode, 'stderr' => $result->stderr]);
        }

        return $result->succeeded();
    }

    public function startLater(): bool
    {
        try {
            $result = $this->processes->run(new ProcessInvocation([
                'sudo', 'systemd-run', '--quiet', '--collect', '--on-active='.self::RestartDelaySeconds,
                '--unit=orbit-fleet-converge-restart-'.bin2hex(random_bytes(4)),
                'systemctl', 'start', '--no-block', $this->units->serviceName(),
            ]));
        } catch (Throwable $exception) {
            Log::warning('A later fleet rollout run could not be scheduled.', ['error' => $exception->getMessage()]);

            return false;
        }

        return $result->succeeded();
    }

    private function install(string $path, string $contents, string $step): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $source = stream_get_meta_data($input->stream())['uri'] ?? null;

            if (! is_string($source) || $source === '') {
                throw new RuntimeException('The fleet unit file is unavailable.');
            }

            $this->run($step, ['sudo', 'install', '-m', '0644', $source, $path]);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: 'gateway.fleet_units_install_failed',
                message: "Gateway fleet unit step [{$step}] failed.",
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
            errorCode: 'gateway.fleet_units_install_failed',
            message: "Gateway fleet unit step [{$step}] failed.",
            result: $result,
        );
    }
}
