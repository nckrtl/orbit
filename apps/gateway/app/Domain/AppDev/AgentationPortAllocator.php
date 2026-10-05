<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Shared allocation for app-owned annotation servers. */
final readonly class AgentationPortAllocator
{
    public function assign(Instance $instance, string $column = 'agentation_port', ?string $app = null): int
    {
        $app = $instance->appConfiguration($app)['name'];

        return DB::transaction(function () use ($instance, $column, $app): int {
            Node::query()->whereKey($instance->node_id)->lockForUpdate()->firstOrFail();
            $recorded = $instance->runtimeForApp($app)[$column] ?? null;
            if (is_int($recorded) && $recorded >= $this->minimum($column)) {
                $this->retain($instance, $column, $app);

                return $recorded;
            }
            $port = $this->nextAvailable($instance->node_id, $instance->id, $column, app: $app);
            $instance->recordAppRuntime($app, [$column => $port]);
            if (count($instance->effectiveApps()) === 1) {
                $instance->forceFill([$column => $port])->save();
            }
            $this->retain($instance, $column, $app);

            return $port;
        });
    }

    /** @param list<int> $reserved */
    public function nextAvailable(int $nodeId, int $ignoreInstanceId, string $column = 'agentation_port', array $reserved = [], ?string $app = null): int
    {
        if ($ignoreInstanceId > 0 && $app === null) {
            $app = Instance::query()->findOrFail($ignoreInstanceId)->appConfiguration()['name'];
        }
        $used = DB::table('annotation_port_assignments')->where('node_id', $nodeId)
            ->where(static fn ($query) => $query->where('instance_id', '!=', $ignoreInstanceId)->orWhere('app', '!=', $app)->orWhere('kind', '!=', $column))
            ->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all();
        $used = [...$used, ...DB::table('vite_port_assignments')->where('node_id', $nodeId)->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all()];
        foreach (Instance::query()->with('project')->where('node_id', $nodeId)->get() as $instance) {
            foreach ($instance->effectiveApps() as $configuration) {
                foreach (['vite_port', 'agentation_port', 'annotator_port'] as $kind) {
                    if ($instance->id === $ignoreInstanceId && $configuration['name'] === $app && $kind === $column) {
                        continue;
                    }
                    $port = $instance->runtimeForApp($configuration['name'])[$kind] ?? null;
                    if (is_int($port)) {
                        $used[] = $port;
                    }
                }
            }
        }
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

    public function release(Instance $instance, string $column = 'agentation_port', ?string $app = null): void
    {
        $this->minimum($column);
        $app = $instance->appConfiguration($app)['name'];
        DB::transaction(function () use ($instance, $column, $app): void {
            Node::query()->whereKey($instance->node_id)->lockForUpdate()->firstOrFail();
            $this->releaseOnNode($instance, $instance->node_id, $column, $app);
            $instance->recordAppRuntime($app, [$column => null]);
            if (count($instance->effectiveApps()) === 1) {
                $instance->forceFill([$column => null])->save();
            }
        });
    }

    /** Retain the old placement's port until its Caddy proxy has been withdrawn. */
    public function retain(Instance $instance, string $column, ?string $app = null): void
    {
        $this->minimum($column);
        $app = $instance->appConfiguration($app)['name'];
        $port = $instance->runtimeForApp($app)[$column] ?? null;
        if (! is_int($port)) {
            return;
        }
        DB::table('annotation_port_assignments')->updateOrInsert(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'app' => $app, 'kind' => $column], ['port' => $port]);
    }

    public function releaseOnNode(Instance $instance, int $nodeId, string $column, ?string $app = null): void
    {
        $this->minimum($column);
        $app = $instance->appConfiguration($app)['name'];
        DB::table('annotation_port_assignments')->where('instance_id', $instance->id)->where('node_id', $nodeId)->where('app', $app)->where('kind', $column)->delete();
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
