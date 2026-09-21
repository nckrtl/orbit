<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Driver;
use App\Infrastructure\Tasks\T3\T3ThreadCreator;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;

function test_agent_thread(TaskGroup $group, string $externalId, ?Task $task = null): AgentThread
{
    $instance = $group->taskable;
    $nodeId = $instance instanceof AppInstance ? $instance->node_id : null;

    return AgentThread::query()->firstOrCreate([
        'driver' => 't3', 'runtime_key' => $nodeId === null ? 'test:'.$group->id : 'node:'.$nodeId, 'external_id' => $externalId,
    ], [
        'task_group_id' => $group->id, 'task_id' => $task?->id, 'node_id' => $nodeId,
        'role' => $task === null ? 'reviewer' : 'implementer',
        'model' => $task === null ? TaskAgentDefaults::ReviewerModel : TaskAgentDefaults::ImplementerModel,
        'effort' => $task === null ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort,
    ]);
}

function test_link_agent_threads(TaskGroup $group, string $reviewer = 'reviewer-thread', string $implementer = 'implementer-thread'): void
{
    $group->update(['reviewer_agent_thread_id' => test_agent_thread($group, $reviewer)->id]);
    foreach ($group->tasks as $task) {
        $task->update(['implementer_agent_thread_id' => test_agent_thread($group, $implementer, $task)->id]);
    }
}

function test_t3_registry(?T3Dispatcher $dispatcher = null, ?T3ThreadReader $reader = null): AgentDriverRegistry
{
    $parameters = [];
    if ($dispatcher !== null) {
        $parameters['dispatcher'] = $dispatcher;
        $parameters['creator'] = new T3ThreadCreator($dispatcher);
    }
    if ($reader !== null) {
        $parameters['reader'] = $reader;
    }

    return new AgentDriverRegistry([app()->makeWith(T3Driver::class, $parameters)]);
}

function test_agent_observer(T3ThreadReader $reader): AgentThreadObserver
{
    return new AgentThreadObserver(test_t3_registry(reader: $reader));
}
