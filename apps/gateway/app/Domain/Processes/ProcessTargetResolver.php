<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Process;
use SensitiveParameter;

final readonly class ProcessTargetResolver
{
    public function resolve(ProcessTargetType $type, int $id): ProcessTarget
    {
        return $this->forAdmission(
            AppInstance::query()
                ->with('node')
                ->findOrFail($id),
        );
    }

    public function forAdmission(AppInstance $instance): ProcessTarget
    {
        $instance->loadMissing('node');
        $this->ensureActive($instance);

        return $this->context($instance);
    }

    public function forProcess(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->forAdmission($this->owner($process));
    }

    public function forStart(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->forAdmission($this->owner($process));
    }

    public function forInspection(#[SensitiveParameter] Process $process): ProcessTarget
    {
        return $this->context($this->owner($process));
    }

    public function forRemoval(#[SensitiveParameter] Process $process): ProcessTarget
    {
        $instance = $this->owner($process);
        $this->ensureLinux($instance);

        if ($instance->node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'process.target_inactive',
                message: "Node [{$instance->node->name}] is not active.",
            );
        }

        return $this->context($instance);
    }

    private function owner(#[SensitiveParameter] Process $process): AppInstance
    {
        if ($process->owner_type !== AppInstance::class) {
            throw new ResourceOperationException(
                errorCode: 'process.target_unsupported',
                message: 'The Process owner is not a supported AppInstance.',
                status: 409,
            );
        }

        return AppInstance::query()
            ->with('node')
            ->findOrFail($process->owner_id);
    }

    private function context(AppInstance $instance): ProcessTarget
    {
        $this->ensureLinux($instance);

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
        );
    }

    private function ensureActive(AppInstance $instance): void
    {
        $this->ensureLinux($instance);

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

    private function ensureLinux(AppInstance $instance): void
    {
        if ($instance->node->platform === 'linux') {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'process.platform_unsupported',
            message: "Processes are not supported on [{$instance->node->platform}] nodes yet.",
        );
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
}
