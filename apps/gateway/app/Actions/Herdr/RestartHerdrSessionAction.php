<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspection;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use Throwable;

final readonly class RestartHerdrSessionAction
{
    public function __construct(
        private RequireHerdrToolAction $requireTool,
        private HerdrSessionInspector $inspector,
        private RestartProcessAction $restartProcess,
        private HerdrObserverPublisher $observers,
        private HerdrObserveContract $contract,
    ) {}

    public function execute(HerdrSession $session, bool $handoff): HerdrSession
    {
        $session->loadMissing('node', 'process');
        $this->requireTool->execute($session->node);

        if ($session->process === null) {
            throw new ResourceOperationException(
                errorCode: 'herdr.session_unhealthy',
                message: "Herdr session [{$session->session}] has no managed Process.",
                status: 422,
            );
        }

        $usedHandoff = false;

        if ($handoff) {
            try {
                $inspection = $this->inspector->inspect($session, $session->node);
                $usedHandoff = $inspection->handoffSupported;
            } catch (Throwable) {
                $usedHandoff = false;
            }
        }

        if ($usedHandoff) {
            $this->inspector->handoff($session, $session->node);
        } else {
            $this->restartProcess->execute($session->process);
        }

        if ($session->publish_observer) {
            $this->verifyObserverCapability($session);

            try {
                $publication = $this->observers->publish($session, $session->node);
                $session->update([
                    'observer_url' => $publication->url,
                    'observer_status' => $publication->published ? 'published' : 'failed',
                    'observer_error' => $publication->error,
                    'status' => $publication->published ? LifecycleStatus::Active : LifecycleStatus::Failed,
                    'failed_step' => $publication->published ? null : 'observer',
                    'error_code' => $publication->published ? null : 'herdr.observer_failed',
                ]);
            } catch (Throwable) {
                $session->update([
                    'observer_status' => 'failed',
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'observer',
                    'error_code' => 'herdr.observer_failed',
                ]);
            }
        }

        return $session->refresh()->load('node');
    }

    private function verifyObserverCapability(HerdrSession $session): void
    {
        try {
            $inspection = $this->inspector->inspect($session, $session->node);
            $this->recordInspection($session, $inspection);
            $this->contract->assertCompatible($inspection);
        } catch (ResourceOperationException $exception) {
            $this->recordObserverFailure($session, $exception->errorCode);

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordObserverFailure($session, 'herdr.inspection_failed');

            throw new ResourceOperationException(
                errorCode: 'herdr.inspection_failed',
                message: 'Herdr session inspection failed.',
                status: 422,
                previous: $exception,
            );
        }
    }

    private function recordInspection(HerdrSession $session, HerdrSessionInspection $inspection): void
    {
        $session->update([
            'herdr_version' => $inspection->version,
            'protocol' => $inspection->protocol,
            'handoff_supported' => $inspection->handoffSupported,
        ]);
    }

    private function recordObserverFailure(HerdrSession $session, string $errorCode): void
    {
        $session->update([
            'observer_status' => 'failed',
            'observer_error' => 'observer capability verification failed',
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'observer',
            'error_code' => $errorCode,
        ]);
    }
}
