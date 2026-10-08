<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use SensitiveParameter;

final readonly class ProcessTargetResolver
{
    public function resolve(ProcessTargetType $type, int $id, ?string $app = null): ProcessTarget
    {
        return match ($type) {
            ProcessTargetType::Instance => $this->forAdmission(
                Instance::query()
                    ->with('node')
                    ->findOrFail($id),
                $app,
            ),
            ProcessTargetType::Node => $this->forNodeAdmission(
                Node::query()->findOrFail($id),
            ),
        };
    }

    public function forAdmission(Instance $instance, ?string $app = null): ProcessTarget
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);
        AppRuntimeMigration::assertInstanceAvailable($instance);
        $instance->loadMissing('node');
        $this->ensureActiveInstance($instance);

        return $this->instanceContext($instance, app: $app);
    }

    public function forNodeAdmission(Node $node): ProcessTarget
    {
        $this->ensureActiveNode($node);

        return $this->nodeContext($node);
    }

    public function forProcess(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->forAdmissionOwner($this->owner($process), $process->app);
    }

    public function forInstallation(#[SensitiveParameter] Process $process): ProcessTarget
    {
        if (! is_string($process->source_definition_id)) {
            return $this->forProcess($process);
        }

        $owner = $this->owner($process);

        if (! $owner instanceof Instance) {
            throw new ResourceOperationException(
                errorCode: 'process.target_unsupported',
                message: 'The Process owner is not a supported Instance.',
                status: 409,
            );
        }

        return $this->forPreparation($owner, $process->app);
    }

    public function forPreparation(Instance $instance, ?string $app = null): ProcessTarget
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);
        $instance->loadMissing('node');
        $this->ensureLinux($instance->node);

        if (
            $instance->node->status !== LifecycleStatus::Active
            || $instance->status === InstanceState::Removing
        ) {
            throw new ResourceOperationException(
                errorCode: 'process.target_inactive',
                message: "Instance [{$instance->name}] or its Node is not available for preparation.",
            );
        }

        return $this->instanceContext($instance, app: $app);
    }

    public function forStart(#[SensitiveParameter] Process $process): ProcessTarget
    {
        if ($process->endpoint_withdrawal_started_at !== null) {
            throw new ResourceOperationException('process.removal_pending', 'Finish removing this Process before creating or starting it again.', 409);
        }

        return $this->forAdmissionOwner($this->owner($process), $process->app);
    }

    public function forInspection(#[SensitiveParameter] Process $process): ProcessTarget
    {
        $owner = $this->owner($process);

        return $owner instanceof Node
            ? $this->nodeContext($owner)
            : $this->instanceContext($owner, app: $process->app);
    }

    public function forRemoval(#[SensitiveParameter] Process $process): ProcessTarget
    {
        $owner = $this->owner($process);

        if ($owner instanceof Node) {
            $this->ensureLinux($owner);

            if ($owner->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException(
                    errorCode: 'process.target_inactive',
                    message: "Node [{$owner->name}] is not active.",
                );
            }

            return $this->nodeContext($owner);
        }

        $this->ensureLinux($owner->node);

        if ($owner->node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'process.target_inactive',
                message: "Node [{$owner->node->name}] is not active.",
            );
        }

        return $this->instanceContext($owner, allowRemovingRole: true, app: $process->app);
    }

    private function forAdmissionOwner(Instance|Node $owner, ?string $app): ProcessTarget
    {
        return $owner instanceof Node
            ? $this->forNodeAdmission($owner)
            : $this->forAdmission($owner, $app);
    }

    private function owner(#[SensitiveParameter] Process $process): Instance|Node
    {
        return match (true) {
            Instance::isMorphType($process->owner_type) => Instance::query()
                ->with('node')
                ->findOrFail($process->owner_id),
            $process->owner_type === Node::class => Node::query()->findOrFail($process->owner_id),
            default => throw new ResourceOperationException(
                errorCode: 'process.target_unsupported',
                message: 'The Process owner is not a supported Instance or Node.',
                status: 409,
            ),
        };
    }

    private function nodeContext(Node $node): ProcessTarget
    {
        $this->ensureLinux($node);

        $user = $node->user;
        $workingDirectory = "/home/{$user}";

        if (! $this->safeAbsolutePath($workingDirectory)) {
            $this->nodeUnavailable($node);
        }

        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            $this->nodeUnavailable($node);
        }

        return new ProcessTarget(
            node: $node,
            user: $user,
            checkoutPath: $workingDirectory,
            environmentFile: '',
        );
    }

    private function instanceContext(Instance $instance, bool $allowRemovingRole = false, ?string $app = null): ProcessTarget
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->ensureLinux($instance->node);
        $app = $instance->appConfiguration($app)['name'];

        $appDevPlacement = $instance->placedOnAppDev()
            || ($allowRemovingRole && $instance->node->roles()
                ->where('role', RoleName::AppDev->value)
                ->where('status', LifecycleStatus::Removing->value)
                ->exists());
        $appProdPlacement = $instance->placedOnAppProd()
            || ($allowRemovingRole && $instance->node->roles()
                ->where('role', RoleName::AppProd->value)
                ->where('status', LifecycleStatus::Removing->value)
                ->exists());

        if ($appDevPlacement) {
            $workingDirectory = $instance->applicationDirectory($app);
            $environmentFile = "{$workingDirectory}/.env";
            $user = $instance->node->user;
            $certificateScope = "app-instance-{$instance->id}".($instance->usesAppRuntimeIdentity($app) ? "-app-{$app}" : '');
            $productionReleaseLayout = false;
        } elseif ($appProdPlacement) {
            $home = $instance->production_home;
            $user = $instance->production_user;

            if (! is_string($home) || ! is_string($user)) {
                $this->unavailable($instance);
            }

            if (! $instance->usesProductionReleaseLayout()) {
                $this->unavailable($instance);
            }

            $productionReleaseLayout = true;
            $workingDirectory = $instance->runtimeForApp($app)['laravel'] === true ? $instance->applicationDirectory($app) : "{$home}/current";
            $environmentFile = "{$home}/.env";
            $certificateScope = null;
        } else {
            $this->unavailable($instance);
        }

        if (! $this->safeAbsolutePath($workingDirectory) || ! $this->safeAbsolutePath($environmentFile)) {
            $this->unavailable($instance);
        }

        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            $this->unavailable($instance);
        }

        return new ProcessTarget(
            node: $instance->node,
            user: $user,
            checkoutPath: $workingDirectory,
            certificateScope: $certificateScope,
            instance: $instance,
            environmentFile: $environmentFile,
            productionReleaseLayout: $productionReleaseLayout,
            routeDomain: $this->developmentRouteDomain($instance, $app),
            app: $app,
            onDemandHostStart: new DevelopmentHibernationPolicy()->usesOnDemandHostStart($instance),
        );
    }

    private function developmentRouteDomain(Instance $instance, string $app): ?string
    {
        if (! $instance->placedOnAppDev()) {
            return null;
        }

        $instance->loadMissing('routes');
        $authoritative = $instance->authoritativeRoute($app);
        $domain = $authoritative instanceof Route
            ? $authoritative->domain
            : null;

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function ensureActiveInstance(Instance $instance): void
    {
        $this->ensureLinux($instance->node);

        if (
            $instance->status === InstanceState::Active
            && $instance->node->status === LifecycleStatus::Active
            && $instance->provisioning_step === 'active'
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'process.target_inactive',
            message: "Instance [{$instance->name}] or its Node is not active.",
        );
    }

    private function ensureActiveNode(Node $node): void
    {
        $this->ensureLinux($node);

        if ($node->status === LifecycleStatus::Active && $this->isManaged($node)) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'process.target_inactive',
            message: "Node [{$node->name}] is not active.",
        );
    }

    private function ensureLinux(Node $node): void
    {
        if ($node->platform === 'linux') {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'process.platform_unsupported',
            message: "Processes are not supported on [{$node->platform}] nodes yet.",
        );
    }

    private function isManaged(Node $node): bool
    {
        return is_string($node->wireguard_ip) && $node->wireguard_ip !== '';
    }

    private function safeAbsolutePath(string $path): bool
    {
        return
            str_starts_with($path, '/')
            && ! str_contains($path, "\n")
            && ! str_contains($path, "\r")
            && array_all(
                array_slice(explode('/', $path), 1),
                static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], strict: true),
            );
    }

    private function unavailable(Instance $instance): never
    {
        throw new ResourceOperationException(
            errorCode: 'process.target_unavailable',
            message: "Instance [{$instance->name}] has no valid Process placement.",
            status: 409,
        );
    }

    private function nodeUnavailable(Node $node): never
    {
        throw new ResourceOperationException(
            errorCode: 'process.target_unavailable',
            message: "Node [{$node->name}] has no valid Process placement.",
            status: 409,
        );
    }
}
