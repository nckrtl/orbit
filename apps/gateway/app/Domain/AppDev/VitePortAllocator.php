<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class VitePortAllocator
{
    public const array EXCLUDED_PORTS = [3306, 5432, 5672, 6379, 8000, 8080, 8443, 9000, 9090, 9200, 11211, 15672, 27017];

    public function __construct(private AppDevSourceOperationLock $owner, private VitePortRuntime $runtime) {}

    public function assign(Instance $instance, ?Node $node = null, bool $recheck = false, ?string $app = null): ?int
    {
        if (! $instance->placedOnAppDev()) {
            return null;
        }
        $app = $instance->appConfiguration($app)['name'];
        $node ??= $instance->node;

        return $this->owner->synchronized($node->id, function () use ($instance, $node, $recheck, $app): int {
            $recorded = DB::table('vite_port_assignments')->where('instance_id', $instance->id)->where('node_id', $node->id)->where('app', $app)->value('port');
            $preferred = is_numeric($recorded) ? (int) $recorded : ($instance->runtimeForApp($app)['vite_port'] ?? 5173);
            if ($recorded !== null && ! $recheck) {
                return $preferred;
            }
            $excluded = array_values(array_unique([
                ...self::EXCLUDED_PORTS,
                ...DB::table('vite_port_assignments')->where('node_id', $node->id)->where(static fn ($query) => $query->where('instance_id', '!=', $instance->id)->orWhere('app', '!=', $app))->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all(),
                ...DB::table('annotation_port_assignments')->where('node_id', $node->id)->pluck('port')->map(static fn (mixed $port): int => StoredInteger::from($port))->all(),
            ]));
            $port = $this->runtime->selectPort($node, $preferred, $excluded);
            if ($port < 1024 || $port > 65535 || in_array($port, $excluded, true)) {
                throw new ResourceOperationException('vite.port_invalid', 'The Node returned an invalid Vite port assignment.', 409);
            }
            DB::transaction(function () use ($instance, $node, $port, $app): void {
                DB::table('vite_port_assignments')->updateOrInsert(['instance_id' => $instance->id, 'node_id' => $node->id, 'app' => $app], ['port' => $port]);
                if ($instance->node_id === $node->id) {
                    $instance->recordAppRuntime($app, ['vite_port' => $port]);
                    if (count($instance->effectiveApps()) === 1) {
                        $instance->update(['vite_port' => $port]);
                    }
                }
            });

            return $port;
        });
    }

    public function release(Instance $instance, Node $node, ?string $app = null): void
    {
        $this->owner->synchronized($node->id, static function () use ($instance, $node, $app): int {
            $query = DB::table('vite_port_assignments')->where('instance_id', $instance->id)->where('node_id', $node->id);
            if ($app !== null) {
                $query->where('app', $instance->appConfiguration($app)['name']);
            }

            return $query->delete();
        });
    }
}
