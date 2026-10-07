<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Shared\StoredValue;
use RuntimeException;
use Throwable;

/**
 * Installs the Gateway release units the same way as the runtime hibernator: write each unit,
 * reload systemd, and enable and start the timer. It never starts or restarts a release service, so
 * converging the units from inside a running release leaves that release running.
 */
final readonly class NativeGatewayReleaseUnitConverger implements GatewayReleaseUnitConverger
{
    public function __construct(
        private ProcessRunner $processes,
        private GatewayReleaseUnitRenderer $units = new GatewayReleaseUnitRenderer,
        private string $phpBinary = PHP_BINARY,
        private string $artisan = '',
        private string $orbitHome = '',
        private string $workingDirectory = '',
        private string $user = 'orbit',
    ) {}

    public function converge(): void
    {
        $workingDirectory = $this->workingDirectory !== '' ? $this->workingDirectory : GatewayApplicationPath::resolve();
        $artisan = $this->artisan !== '' ? $this->artisan : $workingDirectory.'/artisan';
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home'));
        $user = $this->user !== '' ? $this->user : 'orbit';

        $this->install(
            $this->units->path($this->units->serviceName()),
            $this->units->renderService($this->phpBinary, $artisan, $orbitHome, $workingDirectory, $user),
            'gateway-release-service',
        );
        $this->install(
            $this->units->path($this->units->runTemplateName()),
            $this->units->renderRunTemplate($this->phpBinary, $artisan, $orbitHome, $workingDirectory, $user),
            'gateway-release-run-service',
        );
        $this->install(
            $this->units->path($this->units->timerName()),
            $this->units->renderTimer(),
            'gateway-release-timer',
        );
        $this->run('gateway-release-reload', ['sudo', 'systemctl', 'daemon-reload']);
        $this->run('gateway-release-enable', ['sudo', 'systemctl', 'enable', '--now', $this->units->timerName()]);
    }

    private function install(string $path, string $contents, string $step): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $metadata = stream_get_meta_data($input->stream());
            $source = $metadata['uri'] ?? null;

            if (! is_string($source) || $source === '') {
                throw new RuntimeException('Gateway release unit file is unavailable.');
            }

            $this->run($step, ['sudo', 'install', '-m', '0644', $source, $path]);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: 'gateway.release_units_install_failed',
                message: "Gateway release unit step [{$step}] failed.",
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
            errorCode: 'gateway.release_units_install_failed',
            message: "Gateway release unit step [{$step}] failed.",
            result: $result,
        );
    }
}
