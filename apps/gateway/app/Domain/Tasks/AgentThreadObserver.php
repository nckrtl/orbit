<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

final readonly class AgentThreadObserver
{
    public function __construct(private AgentDriverRegistry $drivers, private ?TaskBroadcasts $broadcasts = null) {}

    public function observe(AgentThread $thread): ?AgentObservation
    {
        try {
            $observation = $this->drivers->get($thread->driver)->observe($thread);
        } catch (AgentDriverException) {
            $this->persist($thread, ['observation_error' => 'Agent observation unavailable.']);

            return null;
        }

        return $this->record($thread, $observation) ? $observation : null;
    }

    public function record(AgentThread $thread, AgentObservation $observation): bool
    {
        $values = ['observed_at' => now(), 'observation_error' => null];
        if ($observation->state !== null) {
            $values['state'] = $observation->state;
            $values['error'] = $observation->error;
        }
        foreach (['tokens' => $observation->tokens, 'lines_added' => $observation->linesAdded, 'lines_deleted' => $observation->linesDeleted] as $key => $value) {
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $this->persist($thread, $values);
    }

    /** @param array<string, mixed> $values */
    private function persist(AgentThread $thread, array $values): bool
    {
        $version = $thread->observation_version ?? 0;
        $before = [$thread->state, $thread->error, $thread->observation_error];
        $updated = AgentThread::query()->whereKey($thread->id)->where('observation_version', $version)->update([
            ...$values, 'observation_version' => $version + 1,
        ]);
        $thread->refresh();

        if ($updated === 1 && $before !== [$thread->state, $thread->error, $thread->observation_error]) {
            ($this->broadcasts ?? app(TaskBroadcasts::class))->threadChanged($thread->id);
        }

        return $updated === 1;
    }
}
