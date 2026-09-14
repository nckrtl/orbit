<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Actions\Processes\RemoveProcessAction;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Herdr\HerdrSessionManagement;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use Throwable;

final readonly class RemoveHerdrSessionAction
{
    public function __construct(
        private HerdrSessionInspector $inspector,
        private HerdrObserverPublisher $observers,
        private RemoveProcessAction $removeProcess,
    ) {}

    public function execute(HerdrSession $session, bool $acceptTermination): void
    {
        $session->loadMissing('node', 'process');

        if ($session->management === HerdrSessionManagement::Managed && ! $acceptTermination) {
            try {
                $inspection = $this->inspector->inspect($session, $session->node);
            } catch (Throwable) {
                $inspection = null;
            }

            if ($inspection !== null && $inspection->hasLivePanes()) {
                throw new ResourceOperationException(
                    errorCode: 'herdr.session_in_use',
                    message: "Herdr session [{$session->session}] still has live panes.",
                    status: 409,
                );
            }
        }

        $session->update(['status' => LifecycleStatus::Removing]);

        try {
            $this->observers->retract($session, $session->node);
        } catch (Throwable $exception) {
            $session->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => 'observer',
                'error_code' => 'herdr.observer_failed',
            ]);

            throw new ResourceOperationException(
                errorCode: 'herdr.observer_failed',
                message: "Could not retract the observer for Herdr session [{$session->session}].",
                status: 422,
                previous: $exception,
            );
        }

        $process = $session->process;

        $session->update([
            'process_id' => null,
            'observer_status' => 'unpublished',
            'observer_url' => null,
        ]);

        if ($session->management === HerdrSessionManagement::Managed && $process !== null) {
            $this->removeProcess->execute($process);
        }

        $session->delete();
    }
}
