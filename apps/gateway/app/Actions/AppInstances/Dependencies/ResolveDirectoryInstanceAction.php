<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Data\AppInstances\Dependencies\ResolvedDirectoryInstanceData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class ResolveDirectoryInstanceAction
{
    public function __construct(private NodeAccessAuthorizer $authorizer) {}

    public function execute(string $directory, Node $consumer): ResolvedDirectoryInstanceData
    {
        if (! $this->canonical($directory)) {
            throw new ResourceOperationException('dependencies.directory_invalid', 'A canonical absolute directory is required.', 422);
        }

        return DB::transaction(function () use ($directory, $consumer): ResolvedDirectoryInstanceData {
            if (! $this->authorizer->allows($consumer, $consumer)) {
                $this->notFound();
            }
            $matches = AppInstance::query()->where('node_id', $consumer->id)->get()->filter(function (AppInstance $instance) use ($directory): bool {
                $root = match ($instance->environment) {
                    'development' => $instance->checkout_path,
                    'production' => $instance->production_home,
                    default => null,
                };

                return is_string($root) && $root !== '/' && $this->canonical($root)
                    && ($directory === $root || str_starts_with($directory, $root.'/'));
            });
            if ($matches->isEmpty()) {
                $this->notFound();
            }
            if ($matches->count() !== 1) {
                throw new ResourceOperationException('dependencies.target_ambiguous', 'The directory does not select one instance.', 409);
            }
            $instance = $matches->sole();
            if ($instance->status !== AppInstanceState::Active || $instance->migration_required || $instance->removalMember()->exists()) {
                throw new ResourceOperationException('dependencies.instance_unavailable', 'The instance is unavailable for dependency inventory.', 409);
            }

            return new ResolvedDirectoryInstanceData($instance->id, $instance->app_id, $instance->node_id, $instance->environment);
        });
    }

    private function canonical(string $path): bool
    {
        return strlen($path) <= 4096 && str_starts_with($path, '/')
            && preg_match('/[\\\\\x00-\x1f\x7f]/', $path) !== 1
            && ($path === '/' || (! str_ends_with($path, '/')
                && ! array_intersect(explode('/', substr($path, 1)), ['', '.', '..'])));
    }

    private function notFound(): never
    {
        throw new ResourceOperationException('dependencies.target_not_found', 'No accessible instance matches the directory.', 404);
    }
}
