<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSeedResult;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRouteProjector;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRuntime;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppInstances\Transfer\TransferCheckout;
use App\Domain\AppInstances\Transfer\TransferCleanupResult;
use App\Domain\AppInstances\Transfer\TransferSourceCapture;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
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

final class Orb245DestinationGuard implements AppInstanceDestinationGuard
{
    public function assertUnoccupied(Node $node, StoragePath $destination): void {}
}

final class Orb245EnvironmentLock implements AppInstanceEnvironmentOperationLock
{
    public ?Closure $beforeRun = null;

    public function run(array $appInstanceIds, Closure $operation): mixed
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

final class Orb245TransferSource implements AppInstanceTransferSource
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

    public bool $cleanupIncomplete = false;

    public bool $mutatedSource = false;

    public bool $deletedCommon = false;

    public ?string $cleanupCommon = null;

    public AppInstanceSourceLayout $layout = AppInstanceSourceLayout::Checkout;

    public ?string $common = null;

    public function capture(AppInstance $instance): TransferSourceCapture
    {
        $this->calls[] = 'capture';
        $capture = new TransferSourceCapture(
            appInstanceId: $instance->id,
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
            layout: AppInstanceSourceLayout::Checkout,
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
    }

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult
    {
        $this->calls[] = 'cleanup';
        $this->cleanupCommon = $transfer->common_repository_path;

        if ($this->cleanupIncomplete) {
            return new TransferCleanupResult(false, true, ['source-placement']);
        }

        return new TransferCleanupResult(true, true);
    }
}

final class Orb245TransferRuntime implements AppInstanceTransferRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public function pause(AppInstance $instance): void
    {
        $this->calls[] = 'pause';
    }

    public function restore(AppInstance $instance): void
    {
        $this->calls[] = 'restore';
    }

    public function relocate(
        AppInstance $instance,
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

    public function activate(AppInstance $instance): void
    {
        $this->calls[] = 'activate';
    }

    public function cleanupSourceArtifacts(AppInstance $instance, Node $sourceNode, string $sourcePath): void
    {
        $this->calls[] = 'cleanup';
    }
}

final class Orb245SqliteSeeder implements AppInstanceSqliteSeeder
{
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

final class Orb245EnvironmentReader implements AppInstanceEnvironmentReader
{
    public string $contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";

    public function read(AppInstanceEnvironmentContext $context): string
    {
        return $this->contents;
    }
}

final class Orb245EnvironmentWriter implements AppInstanceEnvironmentWriter
{
    public ?string $contents = null;

    public ?string $path = null;

    public ?string $domain = null;

    /** @var array<string, mixed> */
    public array $observed = [];

    public function write(
        AppInstanceEnvironmentContext $context,
        string $contents,
    ): AppInstanceEnvironmentWriteResult {
        $this->contents = $contents;
        $this->path = $context->path;
        $this->domain = $context->routeDomain;
        $this->observed = [
            'node_id' => $context->nodeId,
            'path' => $context->path,
            'domain' => $context->routeDomain,
        ];

        return AppInstanceEnvironmentWriteResult::changed();
    }
}

final class Orb245Projection implements AppInstanceTransferRouteProjector, DevelopmentRouteProjector
{
    /** @var list<string> */
    public array $calls = [];

    public int $httpChecks = 0;

    public bool $failOnce = false;

    public bool $failRetirementOnce = false;

    public function retireSource(AppInstanceTransfer $transfer): void
    {
        $this->calls[] = 'retire';
        if ($this->failRetirementOnce) {
            $this->failRetirementOnce = false;
            throw new ResourceOperationException('instance.transfer_failed', 'Source projection retirement failed.', 409);
        }
    }

    public function converge(AppInstance $appInstance, Route $route): void
    {
        $this->calls[] = 'converge';

        if ($this->failOnce) {
            $this->failOnce = false;

            throw new ResourceOperationException('instance.transfer_failed', 'Route publication failed.', 409);
        }
    }
}
