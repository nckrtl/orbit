<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\NullInstanceProvisioning;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceProvisioning::class => NullInstanceProvisioning::class,
        AgentSpawner::class => NullAgentSpawner::class,
    ];
}
