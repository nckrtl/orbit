<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppInstances\Deployment\DeploymentEvent;
use App\Domain\AppInstances\Deployment\DeploymentOutputStream;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentReleaseState;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentSynchronizer;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Streaming\DeploymentStreamConnection;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Closure;

final readonly class Orb220DeploymentApiFixture
{
    public function __construct(
        public Node $caller,
        public Node $owner,
        public AppInstance $instance,
        public Orb220ProductionDeployment $deployment,
        public Orb220StreamConnection $connection,
    ) {}

    /** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}>|null $steps */
    public static function create(?array $steps = null): self
    {
        $caller = Node::query()->create([
            'name' => 'deployment-stream-caller',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.220',
            'wireguard_ip' => '10.44.0.220',
            'user' => 'orbit',
        ]);
        $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $owner = Node::query()->create([
            'name' => 'deployment-stream-owner',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.221',
            'wireguard_ip' => '10.44.0.221',
            'user' => 'orbit',
        ]);
        $caller->accessibleNodes()->attach($owner->id);
        $app = OrbitApp::query()->create([
            'name' => 'Deployment Stream',
            'slug' => 'deployment-stream',
            'repository_url' => 'https://example.test/deployment-stream.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $owner->id,
            'name' => 'production',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/home/deployment-stream/releases/initial',
            'production_user' => 'deployment-stream',
            'production_home' => '/home/deployment-stream',
            'root' => 'public',
            'branch' => 'main',
            'branch_override' => 'main',
            'deployment_steps' => $steps ?? [
                [
                    'name' => 'prepare',
                    'phase' => 'before_activation',
                    'command' => 'prepare-command',
                    'timeout_seconds' => 30,
                ],
                [
                    'name' => 'finish',
                    'phase' => 'after_activation',
                    'command' => 'finish-command',
                    'timeout_seconds' => 30,
                ],
            ],
            'selected_php_version' => '8.5',
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'status' => 'active',
        ]);
        $deployment = new Orb220ProductionDeployment;
        $connection = new Orb220StreamConnection;

        app()->instance(ProductionDeployment::class, $deployment);
        app()->instance(DeploymentStreamConnection::class, $connection);
        app()->instance(AppInstanceEnvironmentOperationLock::class, new Orb220DeploymentLock);
        app()->instance(AppInstanceEnvironmentSynchronizer::class, new Orb220EnvironmentSynchronizer);
        app()->instance(ProductionPhpRuntimeManager::class, new Orb220PhpRuntimeManager);

        return new self($caller, $owner, $instance->fresh(), $deployment, $connection);
    }
}

final class Orb220ProductionDeployment implements ProductionDeployment
{
    public int $invocations = 0;

    public bool $failPreparation = false;

    public ?string $requestedRelease = null;

    public function prepare(AppInstance $appInstance, string $branch): DeploymentRelease
    {
        $this->invocations++;

        if ($this->failPreparation) {
            throw new ResourceOperationException('deployment.prepare_failed', 'Injected preparation failure.', 409);
        }

        return $this->release('fresh');
    }

    public function executeStep(
        AppInstance $appInstance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        $request->emit(new DeploymentEvent(
            $step->name,
            $step->name === 'prepare' ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr,
            $step->name === 'prepare' ? str_repeat('A', 16 * 1024) : "output-secret\0bytes",
        ));

        if ($request->cancellation->requested()) {
            throw new ProcessCancelledException;
        }

        return new CommandResult(0, '', '', 1, false);
    }

    public function activate(AppInstance $appInstance, DeploymentRelease $release): DeploymentRelease
    {
        return $release;
    }

    public function selected(AppInstance $appInstance): ?DeploymentRelease
    {
        return $this->release('initial');
    }

    public function retained(AppInstance $appInstance, string $name): DeploymentRelease
    {
        $this->invocations++;
        $this->requestedRelease = $name;

        return $this->release($name);
    }

    public function releases(AppInstance $appInstance): DeploymentReleaseState
    {
        return new DeploymentReleaseState(['fresh', 'initial'], 'initial');
    }

    private function release(string $name): DeploymentRelease
    {
        return new DeploymentRelease(
            $name,
            "/home/deployment-stream/releases/{$name}",
            str_repeat('a', 40),
        );
    }
}

final class Orb220StreamConnection implements DeploymentStreamConnection
{
    public bool $disconnected = false;

    public bool $disconnectOnOutput = false;

    /** @var list<string> */
    public array $lines = [];

    public function disconnected(): bool
    {
        return $this->disconnected;
    }

    public function send(string $line): void
    {
        $this->lines[] = $line;
        echo $line;

        if ($this->disconnectOnOutput && str_contains($line, '"type":"output"')) {
            $this->disconnected = true;
        }
    }
}

final readonly class Orb220DeploymentLock implements AppInstanceEnvironmentOperationLock
{
    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final readonly class Orb220EnvironmentSynchronizer implements AppInstanceEnvironmentSynchronizer
{
    public function execute(AppInstance $instance): AppInstanceEnvironmentResult
    {
        return new AppInstanceEnvironmentResult($instance->id, 'sync', false, 0);
    }
}

final readonly class Orb220PhpRuntimeManager implements ProductionPhpRuntimeManager
{
    public function converge(AppInstance $appInstance): void {}

    public function refreshCache(AppInstance $appInstance): void {}

    public function remove(AppInstance $appInstance): void {}
}
