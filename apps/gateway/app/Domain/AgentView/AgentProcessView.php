<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Collection;

/**
 * Answers Process reads from the Gateway's view of the Node agents.
 *
 * A Process's unit or container is `orbit-process-{id}-{name}` on the Node that runs it, which is
 * the only name this class looks up there. Every method answers null when the view of that Node is
 * not fresh, so the caller keeps its SSH or Prometheus path for that Process.
 */
final readonly class AgentProcessView
{
    /** Systemd and Docker states in which the Process runs no program. */
    private const array STOPPED = [
        'systemd' => ['inactive', 'failed'],
        'docker' => ['exited', 'created', 'dead'],
    ];

    public function __construct(private AgentStateView $view) {}

    public function status(Process $process): ?string
    {
        $name = self::unitName($process);
        $nodeId = $this->nodeId($process);

        return $name === null || $nodeId === null
            ? null
            : $this->view->node($nodeId)->status($process->runtime, $name);
    }

    /** Whether the agent lists the Process's exact unit or container. */
    public function lists(Process $process): ?bool
    {
        $name = self::unitName($process);
        $nodeId = $this->nodeId($process);

        return $name === null || $nodeId === null
            ? null
            : $this->view->node($nodeId)->lists($process->runtime, $name);
    }

    /** Whether the Process runs no program, or null when the view cannot say. */
    public function isStopped(Process $process): ?bool
    {
        $status = $this->status($process);

        return $status === null
            ? null
            : in_array($status, self::STOPPED[$process->runtime->value], strict: true);
    }

    /**
     * Statuses for every Process whose Node has a fresh view, keyed by Process id. Each Node's view
     * is read once, and every Instance's Node in one query.
     *
     * @param  Collection<int, Process>  $processes
     * @return array<int, string>
     */
    public function statuses(Collection $processes): array
    {
        $instanceNodes = $this->instanceNodes($processes);
        $views = [];
        $statuses = [];

        foreach ($processes as $process) {
            $name = self::unitName($process);
            $nodeId = $this->nodeId($process, $instanceNodes);

            if ($name === null || $nodeId === null) {
                continue;
            }

            $views[$nodeId] ??= $this->view->node($nodeId);
            $status = $views[$nodeId]->status($process->runtime, $name);

            if ($status !== null) {
                $statuses[(int) $process->id] = $status;
            }
        }

        return $statuses;
    }

    /** The unit or container name the agent reports for a Process, without `.service`. */
    public static function unitName(Process $process): ?string
    {
        if ((int) $process->id < 1 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $process->name) !== 1) {
            return null;
        }

        return "orbit-process-{$process->id}-{$process->name}";
    }

    /** @param array<int, int>|null $instanceNodes */
    private function nodeId(Process $process, ?array $instanceNodes = null): ?int
    {
        if ($process->owner_type === Node::class) {
            return (int) $process->owner_id;
        }

        if (! AppInstance::isMorphType($process->owner_type)) {
            return null;
        }

        if ($instanceNodes !== null) {
            return $instanceNodes[(int) $process->owner_id] ?? null;
        }

        $nodeId = AppInstance::query()->whereKey($process->owner_id)->value('node_id');

        return is_numeric($nodeId) ? (int) $nodeId : null;
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return array<int, int>
     */
    private function instanceNodes(Collection $processes): array
    {
        $instanceIds = $processes
            ->filter(static fn (Process $process): bool => AppInstance::isMorphType($process->owner_type))
            ->map(static fn (Process $process): int => (int) $process->owner_id)
            ->unique()
            ->values()
            ->all();

        if ($instanceIds === []) {
            return [];
        }

        /** @var array<int, int> $nodes */
        $nodes = AppInstance::query()
            ->whereKey($instanceIds)
            ->pluck('node_id', 'id')
            ->map(static fn (mixed $nodeId): int => (int) $nodeId)
            ->all();

        return $nodes;
    }
}
