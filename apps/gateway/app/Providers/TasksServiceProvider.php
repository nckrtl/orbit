<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\HttpT3Dispatcher;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Infrastructure\Tasks\T3AgentSpawner;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceProvisioning::class => TaskWorkspaceProvisioner::class,
        AgentSpawner::class => T3AgentSpawner::class,
        T3Dispatcher::class => HttpT3Dispatcher::class,
        TaskWorkspaceSigner::class => RemoteTaskWorkspaceSigner::class,
    ];
}
