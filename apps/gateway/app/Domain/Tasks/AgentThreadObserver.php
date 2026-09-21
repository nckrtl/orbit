<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

final readonly class AgentThreadObserver
{
    public function __construct(private AgentDriverRegistry $drivers) {}

    public function observe(AgentThread $thread): ?AgentObservation
    {
        try {
            $observation = $this->drivers->get($thread->driver)->observe($thread);
        } catch (AgentDriverException) {
            $thread->update(['observation_error' => 'Agent observation unavailable.']);

            return null;
        }

        $this->record($thread, $observation);

        return $observation;
    }

    public function record(AgentThread $thread, AgentObservation $observation): void
    {
        $values = ['observed_at' => now(), 'observation_error' => $observation->state === null ? 'Agent state unavailable.' : null];
        if ($observation->state !== null) {
            $values['state'] = $observation->state;
            $values['error'] = $observation->error;
        }
        foreach (['tokens' => $observation->tokens, 'lines_added' => $observation->linesAdded, 'lines_deleted' => $observation->linesDeleted] as $key => $value) {
            if ($value !== null) {
                $values[$key] = $value;
            }
        }
        $thread->update($values);
    }
}
