<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use SensitiveParameter;
use Throwable;

final readonly class ScheduleTargetResolver
{
    public function __construct(
        private ScheduleRuntimeAccountResolver $accounts,
    ) {}

    public function resolve(ScheduleTargetType $type, int $id): ScheduleTarget
    {
        if ($id < 1) {
            $this->invalid();
        }

        try {
            return match ($type) {
                ScheduleTargetType::Node => $this->node(Node::query()->findOrFail($id)),
                ScheduleTargetType::AppInstance => $this->appInstance(
                    AppInstance::query()->with('node')->findOrFail($id),
                ),
            };
        } catch (ModelNotFoundException) {
            $this->invalid();
        }
    }

    public function forSchedule(#[SensitiveParameter] Schedule $schedule): ScheduleTarget
    {
        $type = $this->typeForModel($schedule->target_type);
        $target = $this->resolve($type, $schedule->target_id);

        if ($target->node->id !== $schedule->host_node_id) {
            $this->unavailable();
        }

        return $target;
    }

    public function forInstallation(#[SensitiveParameter] Schedule $schedule): ScheduleTarget
    {
        if (! is_string($schedule->source_definition_id)) {
            return $this->forSchedule($schedule);
        }

        if ($schedule->target_type !== AppInstance::class) {
            $this->unavailable();
        }

        $instance = AppInstance::query()
            ->with('node')
            ->findOrFail($schedule->target_id);

        if (
            $instance->node->status !== LifecycleStatus::Active
            || $instance->status === AppInstanceState::Removing
            || $instance->migration_required
        ) {
            $this->unavailable();
        }

        $target = $this->appInstance($instance, requireActive: false);

        if ($target->node->id !== $schedule->host_node_id) {
            $this->unavailable();
        }

        return $target;
    }

    public function forInspection(#[SensitiveParameter] Schedule $schedule): ScheduleTarget
    {
        $type = $this->typeForModel($schedule->target_type);

        try {
            $target = match ($type) {
                ScheduleTargetType::Node => $this->node(
                    Node::query()->findOrFail($schedule->target_id),
                    requireActive: false,
                ),
                ScheduleTargetType::AppInstance => $this->appInstance(
                    AppInstance::query()->with('node')->findOrFail($schedule->target_id),
                    requireActive: false,
                ),
            };
        } catch (ModelNotFoundException) {
            $this->unavailable();
        }

        return $target;
    }

    public function forRemoval(#[SensitiveParameter] Schedule $schedule): ScheduleTarget
    {
        $target = $this->forInspection($schedule);

        if ($target->node->id !== $schedule->host_node_id) {
            $this->unavailable();
        }

        return $target;
    }

    public function typeForModel(string $model): ScheduleTargetType
    {
        return match ($model) {
            Node::class => ScheduleTargetType::Node,
            AppInstance::class => ScheduleTargetType::AppInstance,
            default => $this->invalid(),
        };
    }

    private function node(Node $node, bool $requireActive = true): ScheduleTarget
    {
        $this->assertNode($node, $requireActive);
        $account = $this->account($node, $node->user);
        $this->assertAccount($account, $node);

        return new ScheduleTarget(
            node: $node,
            user: $account->user,
            group: $account->group,
            home: $account->home,
            workingDirectory: $account->home,
            shell: $account->shell,
            loginShell: true,
            appInstance: null,
        );
    }

    private function appInstance(AppInstance $instance, bool $requireActive = true): ScheduleTarget
    {
        $instance->loadMissing('node');
        $this->assertNode($instance->node, $requireActive);

        if (
            $requireActive
            && ($instance->status !== AppInstanceState::Active
                || $instance->migration_required
                || $instance->provisioning_step !== 'active')
        ) {
            $this->unavailable();
        }

        if ($instance->environment === 'development') {
            $account = $this->account($instance->node, $instance->node->user);
            $this->assertAccount($account, $instance->node);
            $workingDirectory = $instance->checkout_path;
            $loginShell = true;
        } elseif ($instance->environment === 'production') {
            $user = $instance->production_user;
            $home = $instance->production_home;

            if (! is_string($user) || ! is_string($home)) {
                $this->unavailable();
            }

            $account = $this->account($instance->node, $user);

            if (
                $account->user !== $user
                || $account->home !== $home
                || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $account->group) !== 1
            ) {
                $this->unavailable();
            }

            $account = new ScheduleRuntimeAccount($user, $account->group, $home, '/bin/bash');
            $workingDirectory = "{$home}/current";
            $loginShell = false;
        } else {
            $this->unavailable();
        }

        if (! $this->safePath($workingDirectory)) {
            $this->unavailable();
        }

        return new ScheduleTarget(
            node: $instance->node,
            user: $account->user,
            group: $account->group,
            home: $account->home,
            workingDirectory: $workingDirectory,
            shell: $account->shell,
            loginShell: $loginShell,
            appInstance: $instance,
        );
    }

    private function assertNode(Node $node, bool $requireActive): void
    {
        if (
            $node->platform !== 'linux'
            || ($requireActive && $node->status !== LifecycleStatus::Active)
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            $this->unavailable();
        }
    }

    private function assertAccount(ScheduleRuntimeAccount $account, Node $node): void
    {
        if (
            $account->user !== $node->user
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $account->user) !== 1
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $account->group) !== 1
            || ! $this->safePath($account->home)
            || ! $this->safePath($account->shell)
        ) {
            $this->unavailable();
        }
    }

    private function account(Node $node, string $user): ScheduleRuntimeAccount
    {
        try {
            return $this->accounts->resolve($node, $user);
        } catch (ScheduleOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->unavailable();
        }
    }

    private function safePath(string $path): bool
    {
        return
            str_starts_with($path, '/')
            && ! str_contains($path, "\n")
            && ! str_contains($path, "\r")
            && array_all(
                array_slice(explode('/', $path), 1),
                static fn (string $part): bool => ! in_array($part, ['', '.', '..'], strict: true),
            );
    }

    private function invalid(): never
    {
        throw new ResourceOperationException(
            ScheduleErrorCode::TargetInvalid->value,
            'The Schedule target is invalid.',
        );
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException(
            ScheduleErrorCode::TargetUnavailable->value,
            'The Schedule target is unavailable.',
            409,
        );
    }
}
