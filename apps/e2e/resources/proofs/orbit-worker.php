<?php

declare(strict_types=1);

/** Drives the production task components, without a scheduler or GitHub publication fixture. */
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnOutcome;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$app = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['orbit.tasks.worker_user' => 'orbit-worker', 'orbit.pi.provider' => 'worker-proof']);
$statePath = '/home/orbit/orbit-worker-proof.json';
$action = $argv[1] ?? '';

function requireProof(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $state */
function saveProof(array $state): void
{
    file_put_contents('/home/orbit/orbit-worker-proof.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}

try {
    if ($action === 'start') {
        requireProof(! file_exists($statePath), 'An earlier proof state exists. Clean up that proof first.');
        requireProof(! Project::query()->where('slug', 'orbit-worker-proof')->exists(), 'Proof Project already exists; refusing adoption.');
        $node = Node::query()->where('name', 'app-dev')->sole();
        $state = ['node' => $node->id, 'settings' => $node->settings, 'project' => null, 'group' => null, 'thread' => null, 'checkout' => null];
        saveProof($state);
        $settings = $node->settings ?? [];
        $settings['pi'] = ['url' => 'http://'.$node->wireguard_ip.':3774', 'token' => str_repeat('disposable-worker-proof-', 3)];
        $node->update(['settings' => $settings]);
        $project = Project::query()->create([
            'slug' => 'orbit-worker-proof', 'name' => 'Disposable orbit-worker proof', 'type' => ProjectType::Monorepo,
            'repository_url' => 'https://github.com/octocat/Hello-World.git', 'default_branch' => 'master',
            'task_workspace_routed' => false, 'root' => '.',
            'task_check' => 'test "$(id -un)" = orbit && printf "GATEWAY_CHECK_MANAGED_PASSED\\n"',
        ]);
        $state['project'] = $project->id;
        saveProof($state);
        $group = Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'Disposable orbit-worker proof', 'brief' => 'Prove one worker edit through Gateway commit and removal.',
            'status' => TaskGroupStatus::Running, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
            'implementer_model' => 'worker-proof/worker-proof', 'reviewer_model' => 'worker-proof/worker-proof',
        ]);
        $state['group'] = $group->id;
        saveProof($state);
        $task = Task::query()->create([
            'parent_id' => $group->id, 'position' => 1, 'title' => 'Write the worker proof file',
            'brief' => 'Check the worker account boundaries, write worker-proof.txt, run its Python test and incus list, then hand off. Do not commit.',
            'status' => TaskStatus::Running,
            'deliverables' => [['id' => 'worker-file', 'type' => 'file', 'path' => 'worker-proof.txt', 'change' => 'created', 'description' => 'Worker-created file']],
        ]);
        $instance = app(InstanceProvisioning::class)->provision(InstanceProvisionIntent::for($group));
        requireProof($instance instanceof Instance, 'Native workspace provision failed.');
        requireProof($instance->node_id === $node->id, 'Workspace was not placed on app-dev.');
        $group->taskable()->associate($instance);
        $group->save();
        $state['checkout'] = $instance->checkout_path;
        saveProof($state);
        $runner = app(TaskCheckRunner::class);
        $process = $runner->start($instance, $project->taskCheckCommand());
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $reading = $runner->read($instance, $process);
            if ($reading->state !== 'running') {
                break;
            }
            sleep(1);
        }
        echo $reading->output;
        requireProof($reading->state === 'finished' && $reading->exitCode === 0, 'Native baseline failed.');
        $threadId = app(AgentSpawner::class)->spawnImplementer($task->fresh());
        requireProof(is_int($threadId), 'Native Pi implementer spawn failed.');
        $task->update(['implementer_agent_thread_id' => $threadId, 'start_commit' => $instance->starting_commit]);
        $state['thread'] = $threadId;
        saveProof($state);
        echo 'WORKER_STARTED '.json_encode(['group' => $group->id, 'subtask' => $task->id, 'thread' => $threadId, 'checkout' => $instance->checkout_path], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    requireProof(is_file($statePath) && ! is_link($statePath), 'Missing proof state.');
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $node = Node::query()->findOrFail($state['node']);
    requireProof($node->name === 'app-dev', 'Proof Node identity changed.');
    $project = $state['project'] === null ? null : Project::query()->find($state['project']);
    if ($project instanceof Project) {
        requireProof($project->slug === 'orbit-worker-proof' && $project->repository_url === 'https://github.com/octocat/Hello-World.git', 'Proof Project identity changed.');
    }
    $group = $state['group'] === null ? null : Task::topLevel()->find($state['group']);
    if ($group instanceof Task) {
        requireProof($group->project_id === $state['project'] && $group->title === 'Disposable orbit-worker proof', 'Proof group identity changed.');
    }

    if ($action === 'commit') {
        requireProof($group instanceof Task && $project instanceof Project, 'Proof group or Project disappeared.');
        $instance = $group->taskable;
        requireProof($instance instanceof Instance && $instance->checkout_path === $state['checkout'], 'Workspace identity changed.');
        $thread = AgentThread::query()->findOrFail($state['thread']);
        requireProof($thread->task_group_id === $group->id, 'Thread identity changed.');
        $driver = app(AgentDriverRegistry::class)->get('pi');
        for ($attempt = 0; $attempt < 90; $attempt++) {
            $observation = $driver->observe($thread);
            if ($observation->state->value !== 'working') {
                break;
            }
            sleep(1);
        }
        echo json_encode($observation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        requireProof($observation->state->value === 'done', 'Pi tool turn did not finish successfully.');
        $outputs = array_values(array_filter($observation->entries, static fn (array $entry): bool => $entry['kind'] === 'activity' && $entry['label'] === 'bash' && str_ends_with($entry['text'], "\nexit code 0")));
        requireProof(count($outputs) === 1, 'Pi did not record one successful bash tool result.');
        $text = explode("\n$ ", $outputs[0]['text'], 2)[0];
        foreach (['WORKER_HOME_DENIED', 'WORKER_SUDO_DENIED', 'WORKER_SSH_DENIED', 'WORKER_EDIT_TEST_PASSED', 'WORKER_INCUS_PASSED'] as $marker) {
            requireProof(str_contains($text, $marker), 'Pi transcript lacks '.$marker);
        }
        $receipt = app(TaskTurnReceipts::class)->read($instance, $thread->id);
        requireProof($receipt?->outcome === TaskTurnOutcome::ReadyForReview && isset($receipt->deliverables['worker-file']), 'Worker handoff receipt missing.');
        $runner = app(TaskCheckRunner::class);
        $process = $runner->start($instance, $project->taskCheckCommand().' && python3 -c \'import pathlib; assert pathlib.Path("worker-proof.txt").read_text() == "orbit-worker proof\\n"\'');
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $reading = $runner->read($instance, $process);
            if ($reading->state !== 'running') {
                break;
            }
            sleep(1);
        }
        echo $reading->output;
        requireProof($reading->state === 'finished' && $reading->exitCode === 0, 'Native handoff check failed.');
        $sha = app(TaskWorkspaceSigner::class)->commit($instance, 'Prove orbit-worker writes a task workspace');
        requireProof(is_string($sha) && $sha !== $instance->starting_commit, 'Gateway did not produce a new commit.');
        $state['commit'] = $sha;
        saveProof($state);
        echo 'GATEWAY_COMMITTED '.$sha."\n";
        exit(0);
    }

    if ($action === 'cleanup') {
        if ($group instanceof Task) {
            foreach (AgentThread::query()->where('task_group_id', $group->id)->get() as $thread) {
                if (! str_starts_with($thread->external_id, 'pending:')) {
                    app(AgentDriverRegistry::class)->get($thread->driver)->archive($thread, 'orbit-worker-proof-cleanup');
                }
            }
            $workspace = app(RemoveTaskWorkspaceAction::class)->find($group);
            if ($workspace instanceof Instance) {
                $state['checkout'] = $workspace->checkout_path;
                saveProof($state);
            }
            app(RemoveTaskWorkspaceAction::class)->execute($group);
            requireProof(! Instance::query()->where('project_id', $state['project'])->exists(), 'Native removal left an Instance.');
            $group->tasks()->delete();
            AgentThread::query()->where('task_group_id', $group->id)->delete();
            $group->delete();
        }
        if ($project instanceof Project) {
            requireProof(! Instance::query()->where('project_id', $project->id)->exists(), 'Project still has Instances.');
            $project->delete();
        }
        $node->update(['settings' => $state['settings']]);
        requireProof(! Project::query()->where('slug', 'orbit-worker-proof')->exists(), 'Project leftover.');
        requireProof($state['group'] === null || ! Task::query()->whereKey($state['group'])->orWhere('parent_id', $state['group'])->exists(), 'Task leftover.');
        requireProof($state['group'] === null || ! AgentThread::query()->where('task_group_id', $state['group'])->exists(), 'Thread leftover.');
        echo 'GATEWAY_REMOVAL_AUDIT '.json_encode(['checkout' => $state['checkout'], 'project' => $state['project'], 'group' => $state['group'], 'instances' => 0, 'tasks' => 0, 'threads' => 0], JSON_THROW_ON_ERROR)."\n";
        // Keep the state until the host driver has audited the app-dev filesystem too.
        exit(0);
    }
    throw new RuntimeException('Unknown proof action.');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
