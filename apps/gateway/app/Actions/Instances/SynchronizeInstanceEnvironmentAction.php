<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizationSnapshot;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Instances\Environment\InstanceTestEnvironmentOutcome;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class SynchronizeInstanceEnvironmentAction implements InstanceEnvironmentSynchronizer, InstanceRouteEnvironmentSynchronizer
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceOperationPreflight $preflight,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceEnvironmentWriter $writer,
        private ?InstanceTestEnvironment $testing = null,
        private ?InstanceTestEnvironmentWriter $testingWriter = null,
        private ?InstanceEnvironmentReader $reader = null,
    ) {}

    public function execute(Instance $instance): InstanceEnvironmentResult
    {
        return $this->operations->run(
            [$instance->id],
            fn (): InstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolve($instance->refresh(), requireActiveNode: true),
            ),
        );
    }

    public function synchronizeRouteDomain(
        Instance $instance,
        InstanceEnvironmentRouteDomain $domain,
    ): InstanceEnvironmentResult {
        return $this->operations->run(
            [$instance->id],
            fn (): InstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolveForRouteTransition(
                    $instance->refresh(),
                    $domain,
                    requireActiveNode: true,
                ),
                // A Route transition rewrites a production file that deploys already own. Refusing
                // here would stall the transition halfway.
                keepWorkloadKeys: false,
            ),
        );
    }

    private function synchronize(
        InstanceEnvironmentContext $context,
        bool $keepWorkloadKeys = true,
    ): InstanceEnvironmentResult {
        $requiredCapacity = $this->store->synchronizationCapacity($context);
        $this->preflight->assertEnvironmentWritable($context, $requiredCapacity);
        $snapshot = $this->store->synchronizationSnapshot($context);
        $contents = $this->renderer->render($context, $snapshot->values());

        if ($keepWorkloadKeys) {
            $this->assertKeepsWorkloadKeys($context, $snapshot);
        }

        $changed = $this->confirmed($this->writer->write($context, $contents));
        $this->store->recordSynchronizedKeys($context, array_keys($snapshot->values()));
        $plan = ($this->testing ?? app(InstanceTestEnvironment::class))->plan($context->instanceId, $snapshot->values());
        $outcome = null;

        if ($plan !== null) {
            $writer = $this->testingWriter ?? app(InstanceTestEnvironmentWriter::class);
            $result = $writer->mergeTesting(
                $context,
                $this->renderer->render($context, $plan->values),
                $plan->managedKeys,
            );
            $testingChanged = $this->confirmed($result);
            $changed = $testingChanged || $changed;
            $outcome = new InstanceTestEnvironmentOutcome(
                status: match (true) {
                    $result->tracked => InstanceTestEnvironmentOutcome::SkippedTracked,
                    $testingChanged => InstanceTestEnvironmentOutcome::Written,
                    default => InstanceTestEnvironmentOutcome::Unchanged,
                },
                testDatabase: $plan->testDatabase,
            );
        }

        return new InstanceEnvironmentResult(
            instanceId: $context->instanceId,
            operation: 'sync',
            changed: $changed,
            keyCount: $snapshot->keyCount(),
            testing: $outcome,
        );
    }

    /**
     * Refuse to replace a workload file that holds keys stored configuration lacks, unless Orbit
     * wrote or detached those keys itself. A file that `env:import` never read keeps its keys.
     */
    private function assertKeepsWorkloadKeys(
        InstanceEnvironmentContext $context,
        InstanceEnvironmentSynchronizationSnapshot $snapshot,
    ): void {
        try {
            $contents = ($this->reader ?? app(InstanceEnvironmentReader::class))->read($context);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'env.import_source_missing') {
                return;
            }

            throw $exception;
        }

        $unowned = $snapshot->unownedKeysIn(self::fileKeys($contents));

        if ($unowned !== []) {
            throw new ResourceOperationException(
                errorCode: 'env.sync_would_drop_keys',
                message: 'The workload environment file has keys that stored configuration lacks. '
                    .'Run env:import to store them before env:sync, or remove them from the file.',
                status: 409,
                details: ['keys' => implode(',', $unowned)],
            );
        }
    }

    /**
     * Read key names leniently, so a file the strict importer rejects still keeps its keys.
     *
     * @return list<string>
     */
    private static function fileKeys(#[\SensitiveParameter] string $contents): array
    {
        preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/m', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function confirmed(InstanceEnvironmentWriteResult $result): bool
    {
        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw new ResourceOperationException(
                errorCode: 'env.sync_unconfirmed',
                message: 'The Instance environment synchronization result is unconfirmed. Retry the request.',
                status: 409,
            );
        }

        return $result->changed;
    }
}
