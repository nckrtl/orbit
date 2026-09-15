<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use Throwable;

final readonly class NativeRuntimeHibernatorConverger implements RuntimeHibernatorConverger
{
    public function __construct(
        private ProcessRunner $processes,
        private RuntimeHibernatorUnitRenderer $units = new RuntimeHibernatorUnitRenderer,
        private string $phpBinary = PHP_BINARY,
        private string $artisan = '',
        private string $orbitHome = '',
        private string $workingDirectory = '',
        private string $user = 'orbit',
        private int $sweepSeconds = RuntimeHibernation::DefaultSweepSeconds,
    ) {}

    public function converge(): void
    {
        $artisan = $this->artisan !== '' ? $this->artisan : base_path('artisan');
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : (string) config('orbit.home');
        $workingDirectory = $this->workingDirectory !== '' ? $this->workingDirectory : base_path();
        $user = $this->user !== '' ? $this->user : 'orbit';
        $service = $this->units->renderService($this->phpBinary, $artisan, $orbitHome, $workingDirectory, $user);
        $timer = $this->units->renderTimer($this->sweepSeconds);

        $this->install($this->units->servicePath(), $service, 'gateway-hibernator-service');
        $this->install($this->units->timerPath(), $timer, 'gateway-hibernator-timer');
        $this->run(
            step: 'gateway-hibernator-reload',
            errorCode: 'gateway.hibernator_install_failed',
            arguments: ['sudo', 'systemctl', 'daemon-reload'],
        );
        $this->run(
            step: 'gateway-hibernator-enable',
            errorCode: 'gateway.hibernator_install_failed',
            arguments: ['sudo', 'systemctl', 'enable', '--now', $this->units->timerName()],
        );
    }

    private function install(string $path, string $contents, string $step): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $metadata = stream_get_meta_data($input->stream());

            $this->run(
                step: $step,
                errorCode: 'gateway.hibernator_install_failed',
                arguments: ['sudo', 'install', '-m', '0644', $metadata['uri'], $path],
            );
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: 'gateway.hibernator_install_failed',
                message: "Gateway hibernator step [{$step}] failed.",
                previous: $exception,
            );
        } finally {
            $input?->close();
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments): void
    {
        $result = $this->processes->run(new ProcessInvocation($arguments));

        if ($result->succeeded()) {
            return;
        }

        throw new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: "Gateway hibernator step [{$step}] failed.",
            result: $result,
        );
    }
}
