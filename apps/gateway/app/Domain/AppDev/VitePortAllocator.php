<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class VitePortAllocator
{
    public const array EXCLUDED_PORTS = [3306, 5432, 5672, 6379, 8000, 8080, 8443, 9000, 9090, 9200, 11211, 15672, 27017];

    public function __construct(private AppDevSourceOperationLock $owner, private VitePortRuntime $runtime) {}

    public function assign(AppInstance $instance, ?Node $node = null, bool $recheck = false): ?int
    {
        if ($instance->environment !== 'development') {
            return null;
        }

        $node ??= $instance->node;

        return $this->owner->synchronized($node->id, function () use ($instance, $node, $recheck): int {
            $recorded = DB::table('vite_port_assignments')->where('app_instance_id', $instance->id)->where('node_id', $node->id)->value('port');
            $preferred = is_numeric($recorded) ? (int) $recorded : ($instance->vite_port ?? 5173);
            if ($recorded !== null && ! $recheck) {
                return $preferred;
            }

            $excluded = array_values(array_unique([
                ...self::EXCLUDED_PORTS,
                ...DB::table('vite_port_assignments')->where('node_id', $node->id)->where('app_instance_id', '!=', $instance->id)->pluck('port')->map(static fn ($port): int => (int) $port)->all(),
            ]));
            $port = $this->runtime->selectPort($node, $preferred, $excluded);
            if ($port < 1024 || $port > 65535 || in_array($port, $excluded, true)) {
                throw new ResourceOperationException('vite.port_invalid', 'The Node returned an invalid Vite port assignment.', 409);
            }

            DB::transaction(function () use ($instance, $node, $port): void {
                DB::table('vite_port_assignments')->updateOrInsert(
                    ['app_instance_id' => $instance->id, 'node_id' => $node->id],
                    ['port' => $port],
                );
                if ($instance->node_id === $node->id) {
                    $instance->update(['vite_port' => $port]);
                }
            });

            return $port;
        });
    }

    public function release(AppInstance $instance, Node $node): void
    {
        $this->owner->synchronized($node->id, fn (): int => DB::table('vite_port_assignments')->where('app_instance_id', $instance->id)->where('node_id', $node->id)->delete());
    }
}
