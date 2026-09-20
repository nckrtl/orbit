<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * Runs Plausible through the same actions an operator uses for any Process, so it gets the
 * existing lifecycle, logs, and Doctor checks. Plausible keeps no state in its container, so a
 * changed specification replaces the Process: a new version, or a storage Process that moved.
 */
final readonly class NativePlausibleRuntimeLifecycle implements PlausibleRuntimeLifecycle
{
    public function __construct(
        private AddProcessAction $add,
        private RemoveProcessAction $remove,
    ) {}

    public function converge(
        Node $node,
        string $version,
        #[SensitiveParameter]
        AnalyticsStorageConnection $storage,
        #[SensitiveParameter]
        string $secretKeyBase,
    ): Process {
        $data = PlausibleProcess::data($node, $version, $storage, $secretKeyBase);

        try {
            return $this->add->execute($data)['process'];
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'process.name_taken') {
                throw $exception;
            }
        }

        $this->remove($node);

        return $this->add->execute($data)['process'];
    }

    public function remove(Node $node): void
    {
        $process = $this->find($node);

        if ($process instanceof Process) {
            $this->remove->execute($process, removedByOwningRole: true);
        }
    }

    public function forget(Node $node): void
    {
        $this->find($node)?->delete();
    }

    private function find(Node $node): ?Process
    {
        return Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', PlausibleProcess::NAME)
            ->first();
    }
}
