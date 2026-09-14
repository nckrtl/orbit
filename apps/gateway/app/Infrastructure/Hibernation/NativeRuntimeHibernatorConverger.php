<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final readonly class NativeRuntimeHibernatorConverger implements RuntimeHibernatorConverger
{
    public function __construct(
        private ProcessRunner $processes,
        private RuntimeHibernatorUnitRenderer $units = new RuntimeHibernatorUnitRenderer,
        private string $phpBinary = PHP_BINARY,
        private string $artisan = '',
        private string $orbitHome = '',
        private string $workingDirectory = '',
        private int $sweepSeconds = RuntimeHibernation::DefaultSweepSeconds,
    ) {}

    public function converge(): void
    {
        $artisan = $this->artisan !== '' ? $this->artisan : base_path('artisan');
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : (string) config('orbit.home');
        $workingDirectory = $this->workingDirectory !== '' ? $this->workingDirectory : base_path();
        $service = $this->units->renderService($this->phpBinary, $artisan, $orbitHome, $workingDirectory);
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
        $this->run(
            step: $step,
            errorCode: 'gateway.hibernator_install_failed',
            arguments: ['sudo', 'install', '-m', '0644', '/dev/stdin', $path],
            input: $contents,
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, ?string $input = null): void
    {
        $result = $this->processes->run(new ProcessInvocation($arguments, input: $input));

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
