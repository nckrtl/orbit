<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\SequentialTaskPullRequestOpener;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3ThreadReader;
use App\Domain\Tasks\TaskAgentStream;
use App\Domain\Tasks\TaskPullRequestOpener;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskWorkspaceCommitReader;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Infrastructure\Tasks\HttpGitHubTaskPullRequestOpener;
use App\Infrastructure\Tasks\HttpT3Dispatcher;
use App\Infrastructure\Tasks\HttpT3ThreadReader;
use App\Infrastructure\Tasks\RemoteTaskPullRequestOpener;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceCommitReader;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Infrastructure\Tasks\T3AgentSpawner;
use App\Infrastructure\Tasks\T3TaskAgentStream;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceProvisioning::class => TaskWorkspaceProvisioner::class,
        AgentSpawner::class => T3AgentSpawner::class,
        T3Dispatcher::class => HttpT3Dispatcher::class,
        T3ThreadReader::class => HttpT3ThreadReader::class,
        TaskAgentStream::class => T3TaskAgentStream::class,
        TaskWorkspaceSigner::class => RemoteTaskWorkspaceSigner::class,
        TaskWorkspaceDiffReader::class => RemoteTaskWorkspaceDiffReader::class,
        TaskWorkspaceCommitReader::class => RemoteTaskWorkspaceCommitReader::class,
        TaskSettleMetricsCollector::class => LocalTaskSettleMetricsCollector::class,
        CoderSettleNotifier::class => HttpCoderSettleNotifier::class,
    ];

    #[\Override]
    public function register(): void
    {
        parent::register();

        $this->app->bind(TaskPullRequestOpener::class, fn (Application $app): SequentialTaskPullRequestOpener => new SequentialTaskPullRequestOpener([
            $app->make(RemoteTaskPullRequestOpener::class),
            $app->make(HttpGitHubTaskPullRequestOpener::class),
        ]));
    }
}
