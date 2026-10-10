<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Builds a host's task VM base image. A builder VM boots the stock image with the builder user-data, runs
 * the programs that enrollment runs on an `app-dev` Node through `incus exec`, installs the pinned agent
 * binary, and removes its identity with `base-image-clean.sh`. Incus publishes its disk as
 * `orbit-task-base-<time>`. A smoke VM boots the new image, which also creates its volume on the ZFS pool.
 * Then the alias `orbit-task-base` moves to it in one request, and older base images that no VM uses go.
 * A failed build keeps the current image and leaves no builder, smoke VM or unused new image behind.
 */
final readonly class IncusTaskVmImageBuilder
{
    public const string BuilderPrefix = 'tvm-image-';

    private const string CleanScript = 'task-vms/base-image-clean.sh';

    private const string Confirmation = '{"ok":true}';

    private const string FingerprintPattern = '/\A256 SHA256:[A-Za-z0-9+\/]{43} .* \(ED25519\)\n?\z/D';

    private const int BootSeconds = 600;

    private const int PollSeconds = 3;

    private const string InstallAgent = <<<'BASH'
        candidate=/usr/local/bin/orbit-agent.orbit-candidate
        curl --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$candidate" -- "$1"
        printf '%s  %s\n' "$2" "$candidate" | sha256sum --check --status
        chown root:root -- "$candidate"
        chmod 0755 -- "$candidate"
        mv -fT -- "$candidate" /usr/local/bin/orbit-agent
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private NodeBootstrapCommandFactory $bootstrap,
        private NodeRolePrerequisiteCommandFactory $roles,
    ) {}

    /** Whether the host's project has a base image. */
    public function exists(TaskVmHost $host, Node $node): bool
    {
        return $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) !== null;
    }

    /**
     * Builds the host's base image and returns its fingerprint. `$progress` hears each stage with its seconds.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.image_build_running` while another build of the host runs, else `task_vm.image_build_failed`
     */
    public function build(TaskVmHost $host, Node $node, ?Closure $progress = null): string
    {
        $lock = Cache::lock('task-vms:image-build:'.$node->id, 3600);
        if (! $lock->get()) {
            throw new TaskVmException('task_vm.image_build_running', "A base image build of Node [{$node->name}] is already running.");
        }

        $stamp = now()->format('YmdHis');
        $builder = self::BuilderPrefix.$stamp;
        $smoke = $builder.'-smoke';
        $alias = TaskVmHost::BaseImage.'-'.$stamp;
        $programs = $this->enrollmentPrograms();
        $unused = null;

        try {
            $this->stage($node, 'sweep', $progress, fn () => $this->sweep($host, $node));
            $this->stage($node, 'launch', $progress, fn () => $this->launch($host, $node, $builder, TaskVmHost::SourceImage, $this->cloudInit->renderImageBuilder($this->keys->publicKey())));
            $this->stage($node, 'cloud-init', $progress, fn () => $this->awaitCloudInit($host, $node, $builder));
            foreach ($programs as $name => $program) {
                $this->stage($node, $name, $progress, fn () => $this->runProgram($host, $node, $builder, $name, $program));
            }
            $this->stage($node, 'agent-binary', $progress, fn () => $this->installAgent($host, $node, $builder));
            $this->stage($node, 'clean', $progress, fn () => $this->clean($host, $node, $builder));
            $unused = $published = $this->stage($node, 'publish', $progress, fn (): string => $this->publish($host, $node, $builder, $alias));
            $this->stage($node, 'delete-builder', $progress, fn () => $this->delete($host, $node, $builder));
            $this->stage($node, 'smoke', $progress, fn () => $this->smoke($host, $node, $smoke, $alias));
            $this->stage($node, 'promote', $progress, fn () => $this->promote($host, $node, $published));
            // The new image is the base image now, so a failed prune leaves it in place.
            $unused = null;
            $this->stage($node, 'prune', $progress, fn (): array => $this->prune($host, $node, $published));

            return $published;
        } catch (TaskVmException $exception) {
            $this->discard($host, $node, [$builder, $smoke], $unused);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs one build stage and reports its seconds. A failure names the stage.
     *
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    private function stage(Node $node, string $name, ?Closure $progress, Closure $work): mixed
    {
        $started = microtime(true);
        try {
            $result = $work();
        } catch (Throwable $exception) {
            throw new TaskVmException(
                'task_vm.image_build_failed',
                "The base image build of Node [{$node->name}] failed at stage [{$name}]: ".mb_substr($exception->getMessage(), 0, 2000),
                502,
                $exception,
            );
        }
        if ($progress instanceof Closure) {
            $progress($name, round(microtime(true) - $started, 1));
        }

        return $result;
    }

    /** Deletes builder and smoke VMs that an interrupted build left. */
    private function sweep(TaskVmHost $host, Node $node): void
    {
        foreach (array_keys($this->instances($host, $node)) as $name) {
            if (str_starts_with($name, self::BuilderPrefix)) {
                $this->delete($host, $node, $name);
            }
        }
    }

    private function launch(TaskVmHost $host, Node $node, string $name, string $image, string $userData): void
    {
        $result = $this->incus->launch($host, $node, $name, $image, $userData);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image build VM [{$name}]", 'launch', $result);
        }
    }

    /** Waits until cloud-init is done without errors. */
    private function awaitCloudInit(TaskVmHost $host, Node $node, string $name): void
    {
        $deadline = now()->getTimestamp() + self::BootSeconds;
        while (true) {
            $result = $this->exec($host, $node, $name, ['cloud-init', 'status', '--format=json']);
            // Until the guest agent runs, `incus exec` fails and prints nothing on stdout.
            if ($result->succeeded() || trim($result->stdout) !== '') {
                $status = IncusHost::json("the base image build VM [{$name}]", $result);
                $errors = $status['errors'] ?? null;
                if (! is_string($status['status'] ?? null) || ! is_array($errors) || ! array_is_list($errors)) {
                    throw IncusHost::invalid("the base image build VM [{$name}]", 'the cloud-init status has no status or errors');
                }
                if ($status['status'] === 'error' || ($status['status'] === 'done' && $errors !== [])) {
                    throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on [{$name}]: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
                }
                if ($status['status'] === 'done') {
                    return;
                }
            }
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on [{$name}] was not done ".self::BootSeconds.' seconds after launch.', 504);
            }
            Sleep::for(self::PollSeconds)->seconds();
        }
    }

    /**
     * The programs that enrollment runs on an `app-dev` Node, rendered by the same factories, as root.
     *
     * @return array<string, RemoteCommand>
     */
    private function enrollmentPrograms(): array
    {
        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $programs = ['bootstrap' => $this->bootstrap->makeWithPasswordlessSudo($node, 'orbit')];
        $caddy = $this->roles->caddyPackage($node, RoleName::AppDev);
        if ($caddy instanceof RemoteCommand) {
            $programs['caddy-package'] = $caddy;
        }
        $programs['app-dev-prerequisites'] = $this->roles->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

        return $programs;
    }

    /**
     * Runs one enrollment program as `orbit` from its home, as enrollment does over SSH: each program
     * starts with `sudo`, and the Vite+ installer needs a working directory that `orbit` can read.
     */
    private function runProgram(TaskVmHost $host, Node $node, string $name, string $stage, RemoteCommand $program): void
    {
        $result = $this->incus->run($host, $node, [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit',
            '--', $name, 'runuser', '-u', 'orbit', '--', ...$program->arguments,
        ], $program->input);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image build VM [{$name}]", "exec {$stage}", $result);
        }
    }

    /** Installs the pinned Node agent binary for the VM's architecture. The agent itself is configured at enrollment. */
    private function installAgent(TaskVmHost $host, Node $node, string $name): void
    {
        $architecture = trim($this->checked($host, $node, $name, 'exec uname', $this->exec($host, $node, $name, ['uname', '-m']))->stdout);
        if (! MachineArchitecture::isValid($architecture) || ! in_array($architecture, NodeAgentFootprint::Architectures, true)) {
            throw IncusHost::invalid("the base image build VM [{$name}]", 'the machine architecture is not a Node agent architecture');
        }
        $this->checked($host, $node, $name, 'exec agent-binary', $this->exec($host, $node, $name, [
            'bash', '-ceu', self::InstallAgent, '--', NodeAgentFootprint::downloadUrl($architecture), NodeAgentFootprint::checksum($architecture),
        ]));
    }

    private function clean(TaskVmHost $host, Node $node, string $name): void
    {
        $script = file_get_contents(resource_path(self::CleanScript));
        $result = $this->exec($host, $node, $name, ['bash', '-s'], is_string($script) ? $script : throw new TaskVmException('task_vm.setup_unavailable', 'The base image clean script is missing.'));
        if (! $result->succeeded() || trim($result->stdout) !== self::Confirmation) {
            throw IncusHost::failed("the base image build VM [{$name}]", 'exec base-image-clean.sh', $result);
        }
    }

    /** Stops the builder and publishes its disk under `$alias`. Returns the new fingerprint. */
    private function publish(TaskVmHost $host, Node $node, string $name, string $alias): string
    {
        $this->checked($host, $node, $name, 'stop', $this->incus->run($host, $node, ['stop', '--', $name]));
        $this->checked($host, $node, $name, 'publish', $this->incus->run($host, $node, ['publish', '--compression', 'none', '--alias', $alias, '--', $name]));

        return $this->incus->aliasTarget($host, $node, $alias)
            ?? throw IncusHost::invalid("the base image build VM [{$name}]", "the published image has no alias [{$alias}]");
    }

    /** The smoke VM must have a host key, which proves `openssh-server` and a clean first boot. */
    private function smoke(TaskVmHost $host, Node $node, string $name, string $alias): void
    {
        $this->launch($host, $node, $name, $alias, $this->cloudInit->render($this->keys->publicKey()));
        $this->awaitCloudInit($host, $node, $name);
        $result = $this->checked($host, $node, $name, 'exec ssh-keygen', $this->exec($host, $node, $name, ['ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']));
        if (preg_match(self::FingerprintPattern, $result->stdout) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it has no ed25519 SSH host key');
        }
        $this->delete($host, $node, $name);
    }

    /** Points `orbit-task-base` at `$fingerprint` in one request, or creates the alias on the first build. */
    private function promote(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        $result = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) === null
            ? $this->incus->run($host, $node, ['image', 'alias', 'create', '--', TaskVmHost::BaseImage, $fingerprint])
            : $this->incus->query($host, $node, 'PATCH', '/1.0/images/aliases/'.TaskVmHost::BaseImage, json_encode(['target' => $fingerprint], JSON_THROW_ON_ERROR));
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image of Node [{$node->name}]", 'image alias', $result);
        }
    }

    /**
     * Deletes every dated base image other than `$keep` that no instance in the project uses.
     *
     * @return list<string> the deleted fingerprints
     */
    private function prune(TaskVmHost $host, Node $node, string $keep): array
    {
        $used = array_values($this->instances($host, $node));
        $deleted = [];
        foreach ($this->incus->images($host, $node) as $fingerprint => $aliases) {
            $dated = array_filter($aliases, static fn (string $alias): bool => str_starts_with($alias, TaskVmHost::BaseImage.'-'));
            if ($fingerprint === $keep || $dated === [] || in_array($fingerprint, $used, true)) {
                continue;
            }
            $result = $this->incus->run($host, $node, ['image', 'delete', '--', $fingerprint]);
            if (! $result->succeeded()) {
                throw IncusHost::failed("the base image [{$fingerprint}]", 'image delete', $result);
            }
            $deleted[] = $fingerprint;
        }

        return $deleted;
    }

    /** Deletes a VM; an absent VM counts as deleted. */
    private function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $result = $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        if (! $result->succeeded() && array_key_exists($name, $this->instances($host, $node))) {
            throw IncusHost::failed("the base image build VM [{$name}]", 'delete', $result);
        }
    }

    /**
     * After a failed build: deletes its VMs and the new image when it never became the base image. Best
     * effort, so the build's own error stays the reported one; the next build sweeps what remains.
     *
     * @param  list<string>  $names
     */
    private function discard(TaskVmHost $host, Node $node, array $names, ?string $image): void
    {
        foreach ($names as $name) {
            $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        }
        if ($image !== null) {
            $this->incus->run($host, $node, ['image', 'delete', '--', $image]);
        }
    }

    /**
     * The instances of the host's project, as name => the fingerprint of the image each one launched.
     *
     * @return array<string, ?string>
     */
    private function instances(TaskVmHost $host, Node $node): array
    {
        $subject = "the instances of Node [{$node->name}]";
        $result = $this->incus->run($host, $node, ['list', '--format', 'json']);
        if (! $result->succeeded()) {
            throw IncusHost::failed($subject, 'list', $result);
        }
        $list = IncusHost::json($subject, $result);
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the instance list is not a JSON list');
        }
        $instances = [];
        foreach ($list as $instance) {
            $name = is_array($instance) ? $instance['name'] ?? null : null;
            $config = is_array($instance) ? $instance['config'] ?? [] : null;
            if (! is_string($name) || ! is_array($config)) {
                throw IncusHost::invalid($subject, 'an instance has no name or config');
            }
            $image = $config['volatile.base_image'] ?? null;
            $instances[$name] = is_string($image) ? $image : null;
        }

        return $instances;
    }

    /** @param  list<string>  $command */
    private function exec(TaskVmHost $host, Node $node, string $name, array $command, ?string $input = null): CommandResult
    {
        return $this->incus->run($host, $node, ['exec', '--', $name, ...$command], $input);
    }

    private function checked(TaskVmHost $host, Node $node, string $name, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the base image build VM [{$name}]", $operation, $result);
    }
}
