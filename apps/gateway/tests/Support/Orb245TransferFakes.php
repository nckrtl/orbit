<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSeedResult;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\TransferCheckout;
use App\Domain\Instances\Transfer\TransferCleanupResult;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use Closure;

final class Orb245Accounts implements ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount
    {
        return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    }
}

final class Orb245DestinationGuard implements InstanceDestinationGuard
{
    public function assertUnoccupied(Node $node, StoragePath $destination): void {}
}

final class Orb245EnvironmentLock implements InstanceEnvironmentOperationLock
{
    public ?Closure $beforeRun = null;

    public function run(array $instanceIds, Closure $operation): mixed
    {
        ($this->beforeRun ?? static fn () => null)();

        return $operation();
    }
}

final class Orb368RouterLock implements ClusterRouterOperationLock
{
    public ?int $ownedClusterId = null;

    public function run(int $clusterId, Closure $operation): mixed
    {
        $this->ownedClusterId = $clusterId;
        try {
            return $operation();
        } finally {
            $this->ownedClusterId = null;
        }
    }
}

final class Orb245SourceLock implements AppDevSourceOperationLock
{
    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb245TransferSource implements InstanceTransferSource
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<TransferSourceCapture> */
    public array $captures = [];

    /** @var list<TransferCheckout> */
    public array $materialized = [];

    /** @var list<string> */
    public array $discarded = [];

    public bool $failMaterialize = false;

    public bool $failDiscard = false;

    public ?Closure $onCall = null;

    public bool $cleanupIncomplete = false;

    public bool $mutatedSource = false;

    public bool $deletedCommon = false;

    public ?string $cleanupCommon = null;

    public InstanceSourceLayout $layout = InstanceSourceLayout::Checkout;

    public ?string $common = null;

    public function capture(Instance $instance, ?string $sqliteSourcePath = null): TransferSourceCapture
    {
        $this->calls[] = 'capture';
        ($this->onCall ?? static fn () => null)('capture');
        $capture = new TransferSourceCapture(
            instanceId: $instance->id,
            nodeId: $instance->node_id,
            layout: $this->layout,
            sourcePath: $instance->checkout_path,
            commonRepositoryPath: $this->common ?? $instance->registration_common_repository_path,
            head: str_repeat('a', 40),
            branch: null,
            detached: true,
            archiveIdentity: '/tmp/orbit-transfer.tar',
            refs: ['main', 'unpublished'],
        );
        $this->captures[] = $capture;

        return $capture;
    }

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
    ): TransferCheckout {
        $this->calls[] = 'materialize';

        if ($this->failMaterialize) {
            throw new ResourceOperationException('instance.transfer_failed', 'Destination checkout failed.', 409);
        }

        $checkout = new TransferCheckout(
            nodeId: $destination->id,
            path: $path->value,
            layout: InstanceSourceLayout::Checkout,
            head: $capture->head,
            branch: $capture->branch,
            detached: $capture->detached,
        );
        $this->materialized[] = $checkout;

        return $checkout;
    }

    public function discardDestination(Node $node, StoragePath $path): void
    {
        $this->discarded[] = $path->value;

        if ($this->failDiscard) {
            throw new ResourceOperationException('instance.transfer_failed', 'Destination rollback failed.', 409);
        }
    }

    public function verifyDestination(InstanceTransfer $transfer): void {}

    public function cleanupSource(InstanceTransfer $transfer): TransferCleanupResult
    {
        $this->calls[] = 'cleanup';
        $this->cleanupCommon = $transfer->common_repository_path;

        if ($this->cleanupIncomplete) {
            return new TransferCleanupResult(false, true, ['source-placement']);
        }

        return new TransferCleanupResult(true, true);
    }
}

final class Orb245TransferRuntime implements InstanceTransferRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public ?Closure $onCall = null;

    public ?Closure $onPause = null;

    public ?Closure $onRestore = null;

    public bool $processArtifactsRemoved = false;

    /** @var list<string> */
    public array $pauseOutcomes = [];

    public function pause(Instance $instance): void
    {
        $this->calls[] = 'pause';
        ($this->onCall ?? static fn () => null)('pause');
        ($this->onPause)?->__invoke($instance);

        if ($this->processArtifactsRemoved) {
            $this->pauseOutcomes[] = 'already-removed';

            return;
        }

        $this->processArtifactsRemoved = true;
        $this->pauseOutcomes[] = 'removed';
    }

    public function restore(Instance $instance): void
    {
        $this->calls[] = 'restore';
        ($this->onRestore)?->__invoke($instance);
    }

    public function relocate(
        Instance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void {
        $this->calls[] = 'relocate';
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            if ($process->working_directory === $sourcePath) {
                $process->update(['working_directory' => $workingDirectory]);
            }
        }

        foreach ($instance->schedules as $schedule) {
            $schedule->update(['host_node_id' => $destination->id]);
        }
    }

    public function activate(Instance $instance): void
    {
        $this->calls[] = 'activate';
        ($this->onCall)?->__invoke('activate');
    }

    public function cleanupSourceArtifacts(Instance $instance, Node $sourceNode, string $sourcePath): void
    {
        $this->calls[] = 'cleanup';
    }
}

final class Orb245SqliteSeeder implements InstanceSqliteSeeder
{
    public bool $cleanupIncomplete = false;

    public int $abandonments = 0;

    public ?Closure $onAbandon = null;

    public function abandon(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): bool
    {
        $this->abandonments++;
        ($this->onAbandon)?->__invoke();

        return ! $this->cleanupIncomplete;
    }

    /** @var list<string> */
    public array $calls = [];

    public ?string $sourcePath = null;

    public ?string $sourceBase = null;

    public ?string $destinationBase = null;

    public function seed(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        string $sourcePath,
    ): SqliteSeedResult {
        $this->calls[] = 'seed';
        $this->sourcePath = $sourcePath;
        $this->sourceBase = $source->basePath;
        $this->destinationBase = $target->basePath;

        return SqliteSeedResult::changed();
    }
}

final class Orb245EnvironmentReader implements InstanceEnvironmentReader
{
    public string $contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";

    public ?ResourceOperationException $failure = null;

    public function read(InstanceEnvironmentContext $context): string
    {
        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }

        return $this->contents;
    }
}

final class Orb245EnvironmentWriter implements InstanceEnvironmentWriter
{
    /** @var array<string, array{path: string, contents: string}> */
    public array $apps = [];

    public ?Closure $onWrite = null;

    public ?string $contents = null;

    public ?string $path = null;

    public ?string $domain = null;

    /** @var array<string, mixed> */
    public array $observed = [];

    public function write(
        InstanceEnvironmentContext $context,
        string $contents,
    ): InstanceEnvironmentWriteResult {
        ($this->onWrite)?->__invoke($context);
        $this->apps[$context->app] = ['path' => $context->path, 'contents' => $contents];
        $this->contents = $contents;
        $this->path = $context->path;
        $this->domain = $context->routeDomain;
        $this->observed = [
            'node_id' => $context->nodeId,
            'path' => $context->path,
            'domain' => $context->routeDomain,
        ];

        return InstanceEnvironmentWriteResult::changed();
    }
}

final class Orb245Projection implements DevelopmentRouteProjector, InstanceTransferRouteProjector
{
    /** @var list<string> */
    public array $calls = [];

    public int $httpChecks = 0;

    public bool $failOnce = false;

    public bool $failRetirementOnce = false;

    public function retireSource(InstanceTransfer $transfer): void
    {
        $this->calls[] = 'retire';
        if ($this->failRetirementOnce) {
            $this->failRetirementOnce = false;
            throw new ResourceOperationException('instance.transfer_failed', 'Source projection retirement failed.', 409);
        }
    }

    public function converge(Instance $instance, Route $route): void
    {
        $this->calls[] = 'converge';

        if ($this->failOnce) {
            $this->failOnce = false;

            throw new ResourceOperationException('instance.transfer_failed', 'Route publication failed.', 409);
        }
    }
}
