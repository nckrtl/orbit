<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use Throwable;

final readonly class RestartHerdrSessionAction
{
    public function __construct(
        private HerdrSessionInspector $inspector,
        private RestartProcessAction $restartProcess,
        private HerdrObserverPublisher $observers,
    ) {}

    public function execute(HerdrSession $session, bool $handoff): HerdrSession
    {
        $session->loadMissing('node', 'process');

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
            try {
                $publication = $this->observers->publish($session, $session->node);
                $session->update([
                    'observer_url' => $publication->url,
                    'observer_status' => $publication->published ? 'published' : 'failed',
                    'observer_error' => $publication->error,
                    'error_code' => $publication->published ? null : 'herdr.observer_failed',
                ]);
            } catch (Throwable) {
                $session->update([
                    'observer_status' => 'failed',
                    'error_code' => 'herdr.observer_failed',
                ]);
            }
        }

        return $session->refresh()->load('node');
    }
}
