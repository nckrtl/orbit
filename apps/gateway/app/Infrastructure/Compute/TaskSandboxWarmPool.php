<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** @phpstan-import-type IncusHost from TaskSandboxDrivers */
final readonly class TaskSandboxWarmPool
{
    public function __construct(private TaskSandboxDrivers $drivers, private ComputeLocks $locks, private TaskSandboxLifecycle $lifecycle, private TaskExtensionState $extension) {}

    /** Atomically transfer recorded ownership before any group credential or guest operation. */
    public function claim(Task $group): ?TaskSandbox
    {
        if ($group->project->slug !== 'orbit' || ! config('compute.incus.enabled', false)) {
            return null;
        }
        $hosts = array_filter($this->drivers->localHosts(), static fn (array $host): bool => $host['warm_pairs'] > 0);
        if ($hosts === []) {
            return null;
        }

        return DB::transaction(function () use ($group, $hosts): ?TaskSandbox {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            if ($locked->task_compute !== TaskCompute::Vm || TaskExecutionHold::active($locked) || $locked->taskable_id !== null
                || in_array($locked->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
                return null;
            }
            $existing = TaskSandbox::query()->where('group_id', $locked->id)->where('state', '!=', SandboxState::Destroyed)->first();
            if ($existing !== null) {
                return $existing;
            }
            $nodes = Node::query()->whereIn('id', array_column($hosts, 'node_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $slots = TaskSandbox::query()->where('warm_pool', true)->whereNull('group_id')->where('provider', 'incus')
                ->where('state', '!=', SandboxState::Destroyed)->where('desired_power', 'running')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $repository = GitHubRepository::fromOrigin((string) $locked->project->repository_url);
            foreach ($hosts as $host) {
                $template = $host['orbit_source_template'];
                if (! $repository instanceof GitHubRepository || $template === null
                    || $template['repository'] !== 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git'
                    || $template['base'] !== $locked->project->default_branch) {
                    continue;
                }
                $node = $nodes->get($host['node_id']);
                if (! $node instanceof Node) {
                    continue;
                }
                foreach ($slots as $slot) {
                    if (self::isUnassigned($slot) && $this->matches($slot, $host, $node)) {
                        $slot->update(['group_id' => $locked->id, 'warm_pool' => false]);

                        return $slot;
                    }
                }
            }

            return null;
        });
    }

    /** One bounded pool operation after normal claims; a lost response retains its UUID. */
    public function reconcile(): int
    {
        $hosts = $this->drivers->localHosts();
        $enabled = config('compute.incus.enabled', false) && config('compute.orbit_claims_enabled', false)
            && config('compute.model_proxy.enabled', false) && $this->extension->enabled();
        $slots = TaskSandbox::query()->where('warm_pool', true)->where('state', '!=', SandboxState::Destroyed)->orderBy('created_at')->orderBy('id')->get();
        $kept = [];
        $retry = null;
        foreach ($slots as $slot) {
            if (! self::isUnassigned($slot)) {
                continue;
            }
            $host = array_find($hosts, static fn (array $host): bool => $host['node_id'] === ($slot->spec['host_id'] ?? null));
            $node = $host === null ? null : Node::query()->find($host['node_id']);
            if ($host === null || ! $node instanceof Node) {
                continue;
            }
            $count = $kept[$host['node_id']] ?? 0;
            if (! $enabled || $slot->desired_power !== 'running' || ! $this->matches($slot, $host, $node) || $count >= $host['warm_pairs']) {
                return $this->drain($slot);
            }
            $kept[$host['node_id']] = $count + 1;
            if ($slot->state !== SandboxState::Running) {
                $retry ??= $slot;
            }
        }
        if (! $enabled || $this->waiting()) {
            return 0;
        }
        if ($retry instanceof TaskSandbox) {
            return $this->prepare($retry);
        }
        foreach ($hosts as $host) {
            $node = Node::query()->find($host['node_id']);
            if (($kept[$host['node_id']] ?? 0) >= $host['warm_pairs'] || ! $node instanceof Node || ! $this->eligible($host, $node)) {
                continue;
            }
            try {
                $available = $this->drivers->local($host)->capacity();
                $slot = $this->reserve($host, $available);
            } catch (Throwable) {
                return 0;
            }
            if ($slot !== null) {
                return $this->prepare($slot);
            }
        }

        return 0;
    }

    /** A marker alone never authorizes credential-free provisioning or pool cleanup. */
    public static function isUnassigned(TaskSandbox $sandbox): bool
    {
        if (! $sandbox->warm_pool || $sandbox->provider !== 'incus' || $sandbox->group_id !== null || $sandbox->node_id !== null) {
            return false;
        }
        if (! Str::isUuid($sandbox->id) || $sandbox->name !== 'ot-'.substr(hash('sha256', $sandbox->id), 0, 10)) {
            return false;
        }
        $spec = $sandbox->spec;
        if (! is_array($spec['source_template'] ?? null) || ! is_array($spec['images'] ?? null)) {
            return false;
        }
        if (count($spec['images']) !== 2 || ! isset($spec['images']['operator'], $spec['images']['gateway'])) {
            return false;
        }
        if ($sandbox->credential_fingerprint !== null || $sandbox->server_id !== null || $sandbox->disk_id !== null || $sandbox->public_address !== null) {
            return false;
        }
        if ($sandbox->model_key !== null || $sandbox->model_proxy_origin !== null || $sandbox->model_key_registered_at !== null || $sandbox->model_key_revoked_at !== null) {
            return false;
        }
        if ($sandbox->pi_token !== null || $sandbox->pi_ready_at !== null || $sandbox->enrollment !== null) {
            return false;
        }

        return ! Instance::query()->where('task_sandbox_id', $sandbox->id)->exists();
    }

    /** @param IncusHost $host */
    private function eligible(array $host, Node $node): bool
    {
        return isset($host['orbit_images']['operator'], $host['orbit_images']['gateway']) && $host['orbit_source_template'] !== null
            && $host['gateway_address'] !== null && $host['model_proxy_origin'] !== null
            && is_string($node->wireguard_ip) && str_starts_with($node->wireguard_ip, '10.44.');
    }

    /** @param IncusHost $host */
    private function matches(TaskSandbox $sandbox, array $host, Node $node): bool
    {
        if (! $this->eligible($host, $node) || ! is_string($sandbox->spec['subnet'] ?? null)
            || preg_match('/\A10\.233\.([0-9]{1,3})\.0\/24\z/', $sandbox->spec['subnet'], $match) !== 1
            || (int) $match[1] < 1 || (int) $match[1] > 254) {
            return false;
        }
        $expected = $this->spec($host, $node, (int) $match[1]);
        $actual = $sandbox->spec;
        ksort($expected);
        ksort($actual);

        return $actual === $expected;
    }

    /** @param IncusHost $host
     * @return array<string, mixed>
     */
    private function spec(array $host, Node $node, int $index): array
    {
        return ['host_id' => $host['node_id'], 'project' => $host['project'], 'pool' => $host['pool'],
            'images' => array_intersect_key($host['orbit_images'], array_flip(['operator', 'gateway'])), 'source_template' => $host['orbit_source_template'],
            'subnet' => '10.233.'.$index.'.0/24', 'blocked_networks' => $host['blocked_networks'], 'pi_host' => $node->wireguard_ip,
            'pi_port' => 23000 + $index, 'gateway_address' => $host['gateway_address'], 'model_proxy_origin' => $host['model_proxy_origin']];
    }

    /** @param IncusHost $host */
    private function reserve(array $host, int $available): ?TaskSandbox
    {
        return DB::transaction(function () use ($host, $available): ?TaskSandbox {
            $node = Node::query()->lockForUpdate()->findOrFail($host['node_id']);
            if (! $this->eligible($host, $node) || $this->waiting()) {
                return null;
            }
            $locals = TaskSandbox::query()->where('provider', 'incus')->where('state', '!=', SandboxState::Destroyed)->get()
                ->filter(static fn (TaskSandbox $row): bool => ($row->spec['host_id'] ?? null) === $host['node_id']);
            if ($locals->filter(static fn (TaskSandbox $row): bool => $row->warm_pool && $row->group_id === null)->count() >= $host['warm_pairs']) {
                return null;
            }
            $reserved = $locals->sum(static function (TaskSandbox $row) use ($host): int {
                if ($row->state === SandboxState::Stopped && $row->desired_power === 'stopped') {
                    return 0;
                }
                $images = $row->spec['images'] ?? null;

                return is_array($images) && $images !== [] ? count($images) : $host['max_vms'];
            });
            if ($available < 2 || $reserved + 2 > $host['max_vms']) {
                return null;
            }
            $used = $locals->map(static fn (TaskSandbox $row): mixed => $row->spec['subnet'] ?? null)->all();
            for ($index = 1; $index < 255; $index++) {
                if (in_array('10.233.'.$index.'.0/24', $used, true)) {
                    continue;
                }
                $id = (string) Str::uuid();

                return TaskSandbox::query()->create(['id' => $id, 'name' => 'ot-'.substr(hash('sha256', $id), 0, 10),
                    'provider' => 'incus', 'warm_pool' => true, 'state' => SandboxState::Reserved, 'desired_power' => 'running', 'spec' => $this->spec($host, $node, $index)]);
            }

            return null;
        });
    }

    private function waiting(): bool
    {
        return Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->whereNull('watched_pr_completion')
            ->where(static fn (Builder $query) => $query->where('task_compute', TaskCompute::Vm)
                ->orWhere(static fn (Builder $unclaimed) => $unclaimed->whereNull('task_compute')->whereHas('project', static fn ($project) => $project->where('task_compute', TaskCompute::Vm))))
            ->whereHas('project', static fn ($query) => $query->where('slug', 'orbit'))
            ->whereIn('status', [TaskGroupStatus::Todo, TaskGroupStatus::WaitingForReview])->whereHas('tasks', static fn ($query) => $query->where('status', TaskStatus::Todo))
            ->get()->contains(static fn (Task $group): bool => ! TaskScheduler::resumeBlocked($group));
    }

    private function prepare(TaskSandbox $sandbox): int
    {
        try {
            return $this->locks->sandbox($sandbox->id, function () use ($sandbox): int {
                $sandbox->refresh();
                if (! self::isUnassigned($sandbox) || $sandbox->desired_power !== 'running') {
                    return 0;
                }
                $this->drivers->forSandbox($sandbox)->provision($sandbox);

                return 1;
            });
        } catch (Throwable) {
            $sandbox->refresh();
            if (self::isUnassigned($sandbox)) {
                $sandbox->update(['error_code' => 'compute.warm_preparation_failed']);
            }

            return 0;
        }
    }

    private function drain(TaskSandbox $sandbox): int
    {
        $admitted = DB::transaction(function () use ($sandbox): bool {
            $sandbox->refresh();
            $locked = TaskSandbox::query()->lockForUpdate()->findOrFail($sandbox->id);
            if (! self::isUnassigned($locked)) {
                return false;
            }
            $locked->update(['desired_power' => 'destroyed']);

            return true;
        });
        if (! $admitted) {
            return 0;
        }
        try {
            $this->lifecycle->destroy($sandbox->refresh(), $this->drivers->forSandbox($sandbox));

            return 1;
        } catch (Throwable) {
            $sandbox->refresh();
            if (self::isUnassigned($sandbox)) {
                $sandbox->update(['error_code' => 'compute.warm_cleanup_pending']);
            }

            return 0;
        }
    }
}
