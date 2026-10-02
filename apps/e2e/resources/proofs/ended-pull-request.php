<?php

// Disposable Gateway fixture/observer. Invoked ONLY by ended-pull-request.sh on its lease.
declare(strict_types=1);

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Infrastructure\Tasks\Pi\PiClient;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require dirname(__DIR__, 4).'/apps/gateway/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function demand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function emit(array $data): void
{
    echo 'ENDED_PR_JSON='.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}

try {
    [$script, $mode, $issue] = array_slice($argv, 0, 3);
    demand(preg_match('/\ATASK-[1-9][0-9]*\z/', $issue) === 1, 'Invalid lease issue');
    $number = substr($issue, 5);
    $dir = '/tmp/orbit-ended-pr-'.$issue;
    $slug = 'ended-pr-'.$number;
    $repo = 'https://github.com/orbit-e2e-proof/'.$slug.'.git';
    $pr = 'https://github.com/orbit-e2e-proof/'.$slug.'/pull/42';
    demand(is_file($dir.'/owner') && trim(file_get_contents($dir.'/owner')) === $issue, 'Fixture ownership marker missing');
    $node = Node::query()->where('name', 'app-dev')->firstOrFail();
    $store = app(GitHubAppStore::class);
    $extension = app(TaskExtensionState::class);

    if ($mode === 'setup') {
        demand(! is_file($dir.'/state.json'), 'Refusing an existing fixture manifest');
        demand(Task::topLevel()->count() === 0 && AgentThread::query()->count() === 0, 'Fresh lease has existing task work');
        demand($store->credentials() === null && $store->registration() === null, 'Refusing an existing GitHub App');
        demand(! $extension->enabled(), 'Expected Tasks disabled on fresh lease');
        demand(! array_key_exists('pi', $node->settings ?? []), 'Refusing an existing Pi Node configuration');
        demand(! Project::query()->where('slug', $slug)->orWhere('repository_url', $repo)->exists(), 'Project identity occupied');
        $state = ['issue' => $issue, 'node_id' => $node->id, 'node_settings' => $node->settings,
            'instance_ids_before' => Instance::query()->orderBy('id')->pluck('id')->all(),
            'project_ids_before' => Project::query()->orderBy('id')->pluck('id')->all()];
        file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'laravel-app', 'root' => 'public',
            'repository_url' => $repo, 'default_branch' => 'main', 'task_workspace_routed' => false]);
        $state['project_id'] = $project->id;
        file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => $slug,
            'brief' => 'Disposable early merged PR proof', 'status' => 'running',
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'notify_coder' => false]);
        $state['group_id'] = $group->id;
        $state['checkout'] = '/home/orbit/apps/'.$slug.'/task-'.$group->id;
        file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
            'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id,
            'checkout_path' => $state['checkout'], 'root' => 'public', 'status' => 'source_resolved', 'task_workspace_routed' => false]);
        $group->taskable()->associate($workspace);
        $group->save();
        $state['instance_id'] = $workspace->id;
        foreach (['completed', 'running', 'todo'] as $position => $status) {
            $task = Task::query()->create(['parent_id' => $group->id, 'position' => $position + 1,
                'title' => ['Approved earlier work', 'Later running work', 'Must never start'][$position],
                'brief' => 'Disposable proof work', 'status' => $status]);
            $state['subtask_ids'][] = $task->id;
        }
        file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $token = hash('sha256', 'disposable-'.$issue.'-pi');
        $node->update(['settings' => [...($node->settings ?? []), 'pi' => ['url' => 'http://'.$node->wireguard_ip.':13774', 'token' => $token]]]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        demand($key !== false && openssl_pkey_export($key, $pem), 'Cannot generate disposable App key');
        $store->put(new GitHubAppCredentials(4242, $slug, $slug, 'orbit-e2e-proof', 'organization', 'https://github.com/apps/'.$slug, $pem));
        $extension->enable();
        emit(['setup' => true, 'group_id' => $group->id, 'checkout' => $state['checkout'], 'repository' => $repo]);
        exit(0);
    }

    $state = json_decode(file_get_contents($dir.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
    demand($state['issue'] === $issue && $state['node_id'] === $node->id, 'Manifest belongs to another lease');
    $project = Project::query()->find($state['project_id'] ?? 0);
    if ($project !== null) {
        demand($project->slug === $slug && $project->repository_url === $repo, 'Fixture Project identity changed');
    }
    $group = Task::topLevel()->find($state['group_id'] ?? 0);
    if ($group !== null) {
        demand($group->project_id === $state['project_id'] && $group->title === $slug, 'Fixture task owner changed');
    }

    if ($mode === 'cleanup') {
        if ($group !== null) {
            if ($group->taskable_id !== null) {
                $owned = $group->taskable;
                demand($owned instanceof Instance && $owned->id === $state['instance_id']
                    && $owned->project_id === $state['project_id'] && $owned->node_id === $state['node_id']
                    && $owned->checkout_path === $state['checkout'] && $owned->branch_override === 'task-'.$group->id,
                    'Refusing removal of a workspace whose fixture identity changed');
                // Uses the real remover, never unlinks a workspace behind its record.
                app(RemoveTaskWorkspaceAction::class)->execute($group);
            }
            AgentThread::query()->where('task_group_id', $group->id)->delete();
            $group->tasks()->delete();
            $group->delete();
            Cache::forget('tasks:branch-pull-request:'.$state['group_id']);
        }
        demand(! Instance::query()->where('project_id', $state['project_id'])->exists(), 'Owned workspace remains');
        if ($project !== null) {
            $project->delete();
        }
        demand($store->credentials()?->slug === $slug || $store->credentials() === null, 'GitHub App owner changed');
        $store->delete();
        Cache::forget('github:pull-request-installation:4242:orbit-e2e-proof/'.$slug);
        $node->update(['settings' => $state['node_settings']]);
        $extension->disable();
        emit(['cleanup' => true]);
        exit(0);
    }
    if ($mode === 'audit') {
        demand($group === null && $project === null, 'Task or Project fixture remains');
        demand(AgentThread::query()->where('task_group_id', $state['group_id'])->count() === 0, 'Agent thread remains');
        demand(Task::query()->where('parent_id', $state['group_id'])->count() === 0, 'Subtask remains');
        demand(Instance::query()->orderBy('id')->pluck('id')->all() === $state['instance_ids_before'], 'Instance inventory changed');
        demand(Project::query()->orderBy('id')->pluck('id')->all() === $state['project_ids_before'], 'Project inventory changed');
        demand($store->credentials() === null && ! $extension->enabled() && $node->settings === $state['node_settings'], 'Temporary configuration remains');
        emit(['leftovers' => 0, 'sample_inventory_preserved' => true, 'configuration_restored' => true]);
        exit(0);
    }
    demand($group !== null && $project !== null, 'Fixture group or Project missing');
    $running = Task::query()->findOrFail($state['subtask_ids'][1]);
    $todo = Task::query()->findOrFail($state['subtask_ids'][2]);
    $client = app(PiClient::class);
    $driver = app(PiDriver::class);

    if ($mode === 'workspace-ready') {
        $commit = $argv[3] ?? '';
        demand(preg_match('/\A[0-9a-f]{40}\z/', $commit) === 1, 'Invalid fixture commit');
        Instance::query()->findOrFail($state['instance_id'])->update(['branch' => 'task-'.$group->id, 'starting_commit' => $commit]);
        emit(['source_evidence_recorded' => true]);
        exit(0);
    }
    if ($mode === 'start-agent') {
        $id = (string) Str::uuid();
        $client->create($node, ['id' => $id, 'cwd' => $state['checkout'], 'model' => 'proof/proof-model', 'thinkingLevel' => 'off', 'appendSystemPrompt' => null]);
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $running->id,
            'node_id' => $node->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id,
            'external_id' => $id, 'role' => 'implementer', 'state' => 'working']);
        $running->update(['implementer_agent_thread_id' => $thread->id]);
        $client->send($node, $id, (string) Str::uuid(), 'Keep this disposable fixture turn running until the model barrier releases.');
        $state['thread_id'] = $thread->id;
        file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        emit(['agent_thread_id' => $thread->id, 'session_id' => $id]);
        exit(0);
    }
    $thread = AgentThread::query()->findOrFail($state['thread_id']);
    if ($mode === 'working' || $mode === 'idle') {
        $snapshot = $client->snapshot($node, $thread->external_id);
        demand(in_array($snapshot['state'], $mode === 'working' ? ['working'] : ['idle', 'done'], true), 'Unexpected real Pi turn state: '.$snapshot['state']);
        emit(['real_pi_state' => $snapshot['state'], 'group_status' => $group->status->value]);
        exit(0);
    }

    // Only the fixture repository's GitHub inputs are substituted. Pi calls stay real.
    Http::preventStrayRequests();
    Http::allowStrayRequests(['http://'.$node->wireguard_ip.':13774/*']);
    $merged = ['number' => 42, 'html_url' => $pr, 'state' => 'closed', 'merged' => true, 'merged_at' => '2026-10-01T22:00:00Z'];
    $requests = [];
    Http::fake(function (Request $request) use ($number, $group, $merged, &$requests) {
        $base = 'https://api.github.com/repos/orbit-e2e-proof/ended-pr-'.$number;
        $url = $request->url();
        if (! str_starts_with($url, 'https://api.github.com/')) {
            return null;
        }
        $requests[] = $request->method().' '.$url;
        if ($url === $base.'/installation' && $request->method() === 'GET') {
            return Http::response(['id' => 9]);
        }
        if ($url === 'https://api.github.com/app/installations/9/access_tokens' && $request->method() === 'POST') {
            demand($request['permissions'] === ['pull_requests' => 'read'], 'Unexpected GitHub token permissions');

            return Http::response(['token' => 'disposable-proof-token'], 201);
        }
        if ($request->method() === 'GET' && parse_url($url, PHP_URL_PATH) === parse_url($base.'/pulls', PHP_URL_PATH)) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            demand($query === ['head' => 'orbit-e2e-proof:task-'.$group->id, 'state' => 'all'], 'Wrong branch watch input');

            return Http::response([$merged]);
        }
        if ($url === $base.'/pulls/42' && $request->method() === 'GET') {
            return Http::response($merged);
        }
        throw new RuntimeException('Unexpected GitHub request: '.$request->method().' '.$url);
    });
    app()->instance(TaskPullRequestWatcher::class, new class implements TaskPullRequestWatcher
    {
        public function status(Task $group): ?string
        {
            return 'merged';
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            return new TaskPullRequestHealth('merged');
        }
    });

    if ($mode === 'tick-pending' || $mode === 'tick-delivered') {
        if ($mode === 'tick-pending') {
            demand($driver->observe($thread)->state?->value === 'working', 'Agent stopped before substituted merge');
        }
        demand(Artisan::call('tasks:tick') === 0, 'Scheduler tick failed');
        echo Artisan::output();
        $group->refresh();
        $running->refresh();
        $todo->refresh();
        $reason = 'Watched pull request ended: '.$pr.' is merged. Open subtasks: #'.$running->id.' '.$running->title.', #'.$todo->id.' '.$todo->title.'.';
        demand($group->status->value === 'running' && $group->pr_url === null && $group->watched_pr_url === $pr, 'Task advanced or reviewed PR changed');
        demand($group->assistance_requested && $group->assistance_reason === $reason, 'Group assistance does not name PR, state, and open subtasks');
        demand($running->assistance_requested && $running->assistance_reason === $reason, 'Running subtask assistance missing');
        demand($todo->status->value === 'todo' && $todo->implementer_agent_thread_id === null && $todo->started_at === null, 'Later subtask started');
        demand(AgentThread::query()->where('task_group_id', $group->id)->count() === 1, 'A new agent or reviewer started');
        demand($running->ended_pr_notice_thread_id === $thread->id && filled($running->ended_pr_notice_key), 'Notice identity missing');
        if (isset($state['notice_key'])) {
            demand($running->ended_pr_notice_key === $state['notice_key'], 'Notice retry changed its stable key');
        } else {
            $state['notice_key'] = $running->ended_pr_notice_key;
            file_put_contents($dir.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        }
        demand($running->ended_pr_notice_state === ($mode === 'tick-pending' ? 'pending' : 'delivered'), 'Notice state wrong');
        $snapshot = $client->snapshot($node, $thread->external_id);
        $messages = array_filter($snapshot['entries'], static fn ($entry) => ($entry['message']['role'] ?? null) === 'user');
        demand(count($messages) === ($mode === 'tick-pending' ? 1 : 2), 'Unexpected real Pi user message count');
        $notices = array_filter($messages, static fn ($entry) => str_contains(json_encode($entry, JSON_THROW_ON_ERROR), 'Watched pull request ended:'));
        demand(count($notices) === ($mode === 'tick-pending' ? 0 : 1), 'Real Pi transcript notice count wrong');
        demand($mode !== 'tick-pending' || $snapshot['state'] === 'working', 'Merge interrupted the live turn');
        $delivered = Activity::query()->where('subject_id', $running->id)->where('subject_type', Task::class)->where('description', 'ended pull request notice delivered')->count();
        demand($delivered === ($mode === 'tick-pending' ? 0 : 1), 'Notice delivery audit count wrong');
        emit(['no_new_subtask' => true, 'assistance' => $reason, 'notice_state' => $running->ended_pr_notice_state,
            'notice_count' => count($notices), 'delivery_activities' => $delivered, 'real_pi_state' => $snapshot['state'], 'substituted_http' => $requests]);
        exit(0);
    }
    if ($mode === 'authorize') {
        // The real Pi server is stopped. This attempt commits the substituted ended-PR
        // receipt, then fails at the real interrupt. The unmodified CLI retries it.
        try {
            app(CompleteTaskGroupAction::class)->execute($group);
            throw new RuntimeException('Completion unexpectedly succeeded with Pi stopped');
        } catch (ResourceOperationException $exception) {
            demand($exception->errorCode === 'tasks.subtask_interrupt_failed', 'Wrong completion failure');
        }
        $group->refresh();
        demand($group->watched_pr_completion === 'merged' && $group->status->value === 'running', 'Durable authorization not preserved');
        demand($running->fresh()->status->value === 'running' && $todo->fresh()->status->value === 'todo', 'Failure cancelled subtasks');
        emit(['authorized_completion' => 'merged', 'real_stop_failed' => true, 'substituted_http' => $requests]);
        exit(0);
    }
    if ($mode === 'completed') {
        demand($group->status->value === 'completed' && $group->taskable_id === null && $group->watched_pr_completion === 'merged', 'CLI completion failed');
        demand($group->tasks()->orderBy('position')->get()->map(static fn (Task $task) => $task->status->value)->all() === ['completed', 'cancelled', 'cancelled'], 'Completion changed wrong subtasks');
        demand(! Instance::query()->whereKey($state['instance_id'])->exists(), 'Workspace Instance remains');
        emit(['group_completed' => true, 'open_subtasks_cancelled' => true, 'workspace_instance_removed' => true]);
        exit(0);
    }
    throw new RuntimeException('Unknown proof mode: '.$mode);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
