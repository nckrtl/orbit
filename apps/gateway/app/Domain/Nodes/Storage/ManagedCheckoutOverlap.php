<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Workspace;

final readonly class ManagedCheckoutOverlap
{
    public function assertAvailable(
        int $nodeId,
        StoragePath $checkout,
        string $errorCode,
        ?int $ignoreInstanceId = null,
        ?int $ignoreWorkspaceId = null,
    ): void {
        $instances = Instance::query()
            ->where('node_id', $nodeId)
            ->when(
                $ignoreInstanceId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreInstanceId),
            )
            ->get(['checkout_path']);

        foreach ($instances as $instance) {
            $managed = StoragePath::tryParse($instance->checkout_path);

            if ($managed instanceof StoragePath && $checkout->overlaps($managed)) {
                $this->taken($checkout, $errorCode);
            }
        }

        $workspaces = Workspace::query()
            ->whereHas('instance', static fn ($query) => $query->where('node_id', $nodeId))
            ->when(
                $ignoreWorkspaceId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreWorkspaceId),
            )
            ->get(['checkout_path']);

        foreach ($workspaces as $workspace) {
            $managed = StoragePath::tryParse($workspace->checkout_path);

            if ($managed instanceof StoragePath && $checkout->overlaps($managed)) {
                $this->taken($checkout, $errorCode);
            }
        }
    }

    private function taken(StoragePath $checkout, string $errorCode): never
    {
        throw new ResourceOperationException(
            errorCode: $errorCode,
            message: "Checkout path [{$checkout->value}] overlaps another managed checkout on this node.",
            status: 409,
        );
    }
}
