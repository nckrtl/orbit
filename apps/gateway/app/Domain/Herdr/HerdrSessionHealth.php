<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\HerdrSession;
use App\Models\Process;

final readonly class HerdrSessionHealth
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
    ) {}

    /**
     * @return array{process: string, listener: string, session: string}
     */
    public function inspect(HerdrSession $session): array
    {
        return [
            'process' => $this->process($session->process),
            'listener' => $this->listener($session),
            'session' => $this->session($session),
        ];
    }

    private function process(?Process $process): string
    {
        if (! $process instanceof Process) {
            return 'unhealthy';
        }

        if ($process->desired_state !== DesiredProcessState::Running) {
            return 'unhealthy';
        }

        $observed = $this->runtime->status($process);

        return in_array($observed, ['running', 'active'], true) ? 'healthy' : 'unhealthy';
    }

    private function listener(HerdrSession $session): string
    {
        if (! $session->publish_observer) {
            return 'healthy';
        }

        return $session->observer_status === 'published' && is_string($session->observer_url)
            ? 'healthy'
            : 'unhealthy';
    }

    private function session(HerdrSession $session): string
    {
        if ($session->status !== LifecycleStatus::Active) {
            return 'unhealthy';
        }

        return $session->herdr_version !== null && $session->protocol === HerdrObserveContract::DefaultProtocol
            ? 'healthy'
            : 'unhealthy';
    }
}
