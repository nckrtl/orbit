<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class ManagedCheckoutOverlap
{
    public function assertAvailable(
        int $nodeId,
        StoragePath $checkout,
        string $errorCode,
        ?int $ignoreAppInstanceId = null,
    ): void {
        $appInstances = AppInstance::query()
            ->where('node_id', $nodeId)
            ->when(
                $ignoreAppInstanceId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreAppInstanceId),
            )
            ->get(['checkout_path']);

        foreach ($appInstances as $appInstance) {
            $managed = StoragePath::tryParse($appInstance->checkout_path);

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
