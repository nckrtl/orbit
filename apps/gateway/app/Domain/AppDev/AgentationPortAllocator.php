<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Shared allocation for the Instance annotation servers. */
final readonly class AgentationPortAllocator
{
    public function assign(Instance $instance, string $column = 'agentation_port'): int
    {
        return DB::transaction(function () use ($instance, $column): int {
            Node::query()->whereKey($instance->node_id)->lockForUpdate()->firstOrFail();
            $recorded = $instance->getAttribute($column);
            if (is_int($recorded) && $recorded >= $this->minimum($column)) {
                $this->retain($instance, $column);

                return $recorded;
            }
            $port = $this->nextAvailable((int) $instance->node_id, $instance->id, $column);
            $instance->forceFill([$column => $port])->save();
            $this->retain($instance, $column);

            return $port;
        });
    }

    /** @param list<int> $reserved */
    public function nextAvailable(int $nodeId, int $ignoreInstanceId, string $column = 'agentation_port', array $reserved = []): int
    {
        $used = Instance::query()->where('node_id', $nodeId)->get(['id', 'agentation_port', 'annotator_port'])
            ->flatMap(static fn (Instance $instance): array => [
                ...($instance->id === $ignoreInstanceId && $column === 'agentation_port' ? [] : [$instance->agentation_port]),
                ...($instance->id === $ignoreInstanceId && $column === 'annotator_port' ? [] : [$instance->annotator_port]),
            ])->all();
        $used = [...$used, ...DB::table('annotation_port_assignments')->where('node_id', $nodeId)
            ->where(fn ($query) => $query->where('instance_id', '!=', $ignoreInstanceId)->orWhere('kind', '!=', $column))
            ->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all()];
        $port = $this->minimum($column);

        while (in_array($port, $used, true) || in_array($port, $reserved, true)) {
            if ($port >= 65_535) {
                $name = $column === 'annotator_port' ? 'annotator' : 'agentation';
                throw new ResourceOperationException("process.{$name}_ports_exhausted", 'No available annotation server port remains on this Node.', 409);
            }
            $port++;
        }

        return $port;
    }

    public function release(Instance $instance, string $column = 'agentation_port'): void
    {
        $this->minimum($column);
        DB::transaction(function () use ($instance, $column): void {
            Node::query()->whereKey($instance->node_id)->lockForUpdate()->firstOrFail();
            $this->releaseOnNode($instance, $instance->node_id, $column);
            $instance->forceFill([$column => null])->save();
        });
    }

    /** Retain the old placement's port until its Caddy proxy has been withdrawn. */
    public function retain(Instance $instance, string $column): void
    {
        $this->minimum($column);
        $port = $instance->getAttribute($column);
        if (! is_int($port)) {
            return;
        }
        DB::table('annotation_port_assignments')->updateOrInsert(
            ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'kind' => $column],
            ['port' => $port],
        );
    }

    public function releaseOnNode(Instance $instance, int $nodeId, string $column): void
    {
        $this->minimum($column);
        DB::table('annotation_port_assignments')->where('instance_id', $instance->id)->where('node_id', $nodeId)->where('kind', $column)->delete();
    }

    private function minimum(string $column): int
    {
        return match ($column) {
            'agentation_port' => AgentationEndpoint::PORT,
            'annotator_port' => AnnotatorEndpoint::PORT,
            default => throw new \InvalidArgumentException('Unsupported annotation server port column.'),
        };
    }
}
