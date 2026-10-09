<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Gives each development Instance its own Inertia SSR port on its Node, like VitePortAllocator. */
final readonly class SsrPortAllocator
{
    /** Inertia's default SSR port. */
    public const int FIRST_PORT = 13714;

    public function __construct(private AppDevSourceOperationLock $owner, private VitePortRuntime $runtime) {}

    public function assign(Instance $instance, ?Node $node = null): ?int
    {
        if (! $instance->placedOnAppDev()) {
            return null;
        }

        $node ??= $instance->node;

        return $this->owner->synchronized($node->id, function () use ($instance, $node): int {
            $recorded = DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->where('node_id', $node->id)->value('port');
            if ($recorded !== null) {
                return StoredInteger::from($recorded);
            }

            $excluded = array_values(array_unique([
                ...VitePortAllocator::EXCLUDED_PORTS,
                ...$this->ports('ssr_port_assignments', $node, $instance),
                ...$this->ports('vite_port_assignments', $node),
                ...$this->ports('annotation_port_assignments', $node),
            ]));
            $port = $this->runtime->selectPort($node, $instance->ssr_port ?? self::FIRST_PORT, $excluded);
            if ($port < 1024 || $port > 65535 || in_array($port, $excluded, true)) {
                throw new ResourceOperationException('ssr.port_invalid', 'The Node returned an invalid SSR port assignment.', 409);
            }

            DB::transaction(function () use ($instance, $node, $port): void {
                DB::table('ssr_port_assignments')->updateOrInsert(
                    ['instance_id' => $instance->id, 'node_id' => $node->id],
                    ['port' => $port],
                );
                if ($instance->node_id === $node->id) {
                    $instance->update(['ssr_port' => $port]);
                }
            });

            return $port;
        });
    }

    public function release(Instance $instance, Node $node): void
    {
        $this->owner->synchronized($node->id, fn (): int => DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->where('node_id', $node->id)->delete());
    }

    /** @return list<int> */
    private function ports(string $table, Node $node, ?Instance $except = null): array
    {
        return array_values(DB::table($table)->where('node_id', $node->id)
            ->when($except !== null, static fn ($query) => $query->where('instance_id', '!=', $except?->id))
            ->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all());
    }
}
