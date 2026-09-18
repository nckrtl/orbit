<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class RenameNodeAction
{
    public function __construct(
        private NodeProvisioningLock $provisioningLock,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Node $node, string $name): Node
    {
        try {
            return $this->provisioningLock->run(
                $node->name,
                function () use ($node, $name): Node {
                    $node->refresh();

                    if ($node->name === $name) {
                        return $node->load('roles');
                    }

                    return $this->provisioningLock->run(
                        $name,
                        fn (): Node => $this->rename($node, $name),
                    );
                },
            );
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function rename(Node $node, string $name): Node
    {
        $node->refresh();

        if ($node->name === $name) {
            return $node->load('roles');
        }

        if ($node->herdrSessions()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'node.has_herdr_sessions',
                message: "Node [{$node->name}] still owns Herdr sessions. Observer hostnames embed the Node name. Destroy those sessions, then rename, then recreate them.",
                status: 409,
            );
        }

        if (Node::query()->where('name', $name)->whereKeyNot($node->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: "The name [{$name}] is already registered.",
                details: ['field' => 'name'],
            );
        }

        $node->name = $name;
        $node->save();

        $result = $node->refresh()->load('roles');

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeUpdated,
            $result->id,
            NodeData::fromModel($result)->toArray(),
        );

        return $result;
    }
}
