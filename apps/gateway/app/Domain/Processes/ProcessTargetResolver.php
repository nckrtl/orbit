<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final readonly class ProcessTargetResolver
{
    public function resolve(ProcessTargetType $type, int $id): ProcessTarget
    {
        return match ($type) {
            ProcessTargetType::AppInstance => $this->forAdmission(
                AppInstance::query()
                    ->with('node')
                    ->findOrFail($id),
            ),
            ProcessTargetType::Node => $this->forNodeAdmission(
                Node::query()->findOrFail($id),
            ),
        };
    }

    public function forAdmission(AppInstance $instance): ProcessTarget
    {
        $instance->loadMissing('node');
        $this->ensureActiveInstance($instance);

        return $this->instanceContext($instance);
    }

    public function forNodeAdmission(Node $node): ProcessTarget
    {
        $this->ensureActiveNode($node);

        return $this->nodeContext($node);
    }

    public function forProcess(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->forAdmissionOwner($this->owner($process));
    }

    public function forInstallation(#[SensitiveParameter] Process $process): ProcessTarget
    {
        if (! is_string($process->source_definition_id)) {
            return $this->forProcess($process);
        }

        $owner = $this->owner($process);

        if (! $owner instanceof AppInstance) {
            throw new ResourceOperationException(
                errorCode: 'process.target_unsupported',
                message: 'The Process owner is not a supported AppInstance.',
                status: 409,
            );
        }

        return $this->forPreparation($owner);
    }

    public function forPreparation(AppInstance $instance): ProcessTarget
    {
        $instance->loadMissing('node');
        $this->ensureLinux($instance->node);

        if (
            $instance->node->status !== LifecycleStatus::Active
            || $instance->status === AppInstanceState::Removing
            || $instance->migration_required
        ) {
            throw new ResourceOperationException(
                errorCode: 'process.target_inactive',
                message: "AppInstance [{$instance->name}] or its Node is not available for preparation.",
            );
        }

        return $this->instanceContext($instance);
    }

    public function forStart(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->forAdmissionOwner($this->owner($process));
    }

    public function forInspection(#[SensitiveParameter] Process $process): ProcessTarget
    {
        $owner = $this->owner($process);

        return $owner instanceof Node
            ? $this->nodeContext($owner)
            : $this->instanceContext($owner);
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

        return $this->instanceContext($owner);
    }

    private function forAdmissionOwner(AppInstance|Node $owner): ProcessTarget
    {
        return $owner instanceof Node
            ? $this->forNodeAdmission($owner)
            : $this->forAdmission($owner);
    }

    private function owner(#[SensitiveParameter] Process $process): AppInstance|Node
    {
        return match ($process->owner_type) {
            AppInstance::class => AppInstance::query()
                ->with('node')
                ->findOrFail($process->owner_id),
            Node::class => Node::query()->findOrFail($process->owner_id),
            default => throw new ResourceOperationException(
                errorCode: 'process.target_unsupported',
                message: 'The Process owner is not a supported AppInstance or Node.',
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

    private function instanceContext(AppInstance $instance): ProcessTarget
    {
        $this->ensureLinux($instance->node);

        if ($instance->environment === 'development') {
            $workingDirectory = $instance->checkout_path;
            $environmentFile = "{$instance->checkout_path}/.env";
            $user = $instance->node->user;
            $certificateScope = "app-instance-{$instance->id}";
            $productionReleaseLayout = false;
        } elseif ($instance->environment === 'production') {
            $home = $instance->production_home;
            $user = $instance->production_user;

            if (! is_string($home) || ! is_string($user)) {
                $this->unavailable($instance);
            }

            $productionReleaseLayout = $instance->usesProductionReleaseLayout();

            if ($instance->checkout_path !== $home && ! $productionReleaseLayout) {
                $this->unavailable($instance);
            }

            $workingDirectory = $productionReleaseLayout ? "{$home}/current" : $home;
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
            appInstance: $instance,
            environmentFile: $environmentFile,
            productionReleaseLayout: $productionReleaseLayout,
            routeHostname: $this->developmentRouteHostname($instance),
            onDemandHostStart: new AppDevHibernationPolicy()->usesOnDemandHostStart($instance),
        );
    }

    private function developmentRouteHostname(AppInstance $instance): ?string
    {
        if ($instance->environment !== 'development') {
            return null;
        }

        $instance->loadMissing('routes');
        $hostname = $instance->routes->sortBy('id')->first()?->hostname;

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    private function ensureActiveInstance(AppInstance $instance): void
    {
        $this->ensureLinux($instance->node);

        if (
            $instance->status === AppInstanceState::Active
            && $instance->node->status === LifecycleStatus::Active
            && ! $instance->migration_required
            && $instance->provisioning_step === 'active'
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'process.target_inactive',
            message: "AppInstance [{$instance->name}] or its Node is not active.",
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

    private function unavailable(AppInstance $instance): never
    {
        throw new ResourceOperationException(
            errorCode: 'process.target_unavailable',
            message: "AppInstance [{$instance->name}] has no valid Process placement.",
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
