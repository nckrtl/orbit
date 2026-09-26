<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Domain\Tasks\TaskPlannerSpawner;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Tasks\AgentViewTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\RemoteTaskPlannerMcp;
use App\Infrastructure\Tasks\RemoteTaskRunReceipts;
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
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        T3Stream::class => T3TaskAgentStream::class,
        InstanceProvisioning::class => TaskWorkspaceProvisioner::class,
        AgentSpawner::class => TaskAgentSpawner::class,
        TaskPlannerSpawner::class => TaskAgentSpawner::class,
        TaskPlannerMcp::class => RemoteTaskPlannerMcp::class,
        T3Dispatcher::class => HttpT3Dispatcher::class,
        T3ThreadReader::class => HttpT3ThreadReader::class,
        TaskWorkspaceSigner::class => RemoteTaskWorkspaceSigner::class,
        TaskWorkspaceDiffReader::class => AgentViewTaskWorkspaceDiffReader::class,
        TaskWorkspaceStateReader::class => RemoteTaskWorkspaceStateReader::class,
        TaskRunReceipts::class => RemoteTaskRunReceipts::class,
        TaskCheckRunner::class => RemoteTaskCheckRunner::class,
        TaskBriefCoverage::class => LaravelAiTaskBriefCoverage::class,
        TaskPullRequestPublisher::class => GitHubTaskPullRequestPublisher::class,
        TaskBaseBranchFetcher::class => GitHubTaskBaseBranchFetcher::class,
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
        $this->app->singleton(TaskBroadcasts::class);
    }

    public function boot(): void
    {
        foreach ([TaskGroup::class, Task::class, TaskCheck::class, TaskComment::class, AgentThread::class] as $model) {
            $model::observe(TaskBroadcastObserver::class);
        }

        // One notice per changed record, when the request or command that changed it ends (ADR 0151).
        $this->app->terminating(function (): void {
            if ($this->app->resolved(TaskBroadcasts::class)) {
                $this->app->make(TaskBroadcasts::class)->flush();
            }
        });
    }
}
