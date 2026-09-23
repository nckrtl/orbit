<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Infrastructure\Tasks\T3\HttpT3Dispatcher;
use App\Infrastructure\Tasks\T3\HttpT3ThreadReader;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Driver;
use App\Infrastructure\Tasks\T3\T3Stream;
use App\Infrastructure\Tasks\T3\T3TaskAgentStream;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        T3Stream::class => T3TaskAgentStream::class,
        InstanceProvisioning::class => TaskWorkspaceProvisioner::class,
        AgentSpawner::class => TaskAgentSpawner::class,
        T3Dispatcher::class => HttpT3Dispatcher::class,
        T3ThreadReader::class => HttpT3ThreadReader::class,
        TaskWorkspaceSigner::class => RemoteTaskWorkspaceSigner::class,
        TaskWorkspaceDiffReader::class => RemoteTaskWorkspaceDiffReader::class,
        TaskWorkspaceStateReader::class => RemoteTaskWorkspaceStateReader::class,
        TaskSettleMetricsCollector::class => LocalTaskSettleMetricsCollector::class,
        CoderSettleNotifier::class => HttpCoderSettleNotifier::class,
        TaskSessionClassifier::class => LaravelAiTaskSessionClassifier::class,
        TaskPullRequestWatcher::class => HttpTaskPullRequestWatcher::class,
    ];

    #[\Override]
    public function register(): void
    {
        parent::register();

        $this->app->bind(AgentDriverRegistry::class, fn (Application $app): AgentDriverRegistry => new AgentDriverRegistry([$app->make(T3Driver::class), $app->make(PiDriver::class)]));

    }
}
