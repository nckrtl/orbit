<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Instances\InstanceProvisionProgress;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

final readonly class NativeProjectStateInspector implements ProjectStateInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    public function inspect(Project $project, Node $node): ProjectInspectionData
    {
        $host = $node->wireguard_ip;
        if (! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        try {
            $account = null;
            $repository = GitRepositoryOrigin::validate($project->repository_url);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        $checkouts = [];
        $instances = $project
            ->instances()
            ->whereNull('task_sandbox_id')->where('node_id', $node->id)
            ->where('status', '!=', InstanceState::Removing)
            ->with(['project', 'tasks'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Instance $instance): bool => ! InstanceSandboxGuard::isSandbox($instance) && ! InstanceProvisionProgress::isInFlight(
                $instance,
                TaskWorkspaceLifecycle::settledState($instance),
            ));
        foreach ($instances as $instance) {
            if ($instance->placedOnAppProd()) {
                $home = $instance->production_home;
                $user = $instance->production_user;
                if (! is_string($home) || $home === '' || ! is_string($user) || $user === '') {
                    throw new DoctorInspectionException;
                }

                $checkouts[] = [
                    'instance_id' => (int) $instance->id,
                    'path' => $instance->checkout_path,
                    'root' => $home,
                    'user' => $user,
                    'slug' => $project->slug,
                    'instance' => $instance->name,
                    'mode' => 'app-prod',
                    'expected_root' => '',
                ];

                continue;
            }

            $account ??= $this->accounts->resolve($node);
            $root = $this->developmentRoot($node, $account);
            $checkouts[] = [
                'instance_id' => (int) $instance->id,
                'path' => $instance->checkout_path,
                'root' => $root,
                'user' => $account->user,
                'slug' => '',
                'instance' => '',
                'mode' => 'app-dev',
                'expected_root' => $root,
            ];
        }
        $match = true;
        $mismatchingInstanceIds = [];
        $failedInstanceIds = [];
        foreach ($checkouts as $checkout) {
            try {
                $result = $this->ssh->execute(
                    new SshConnection(
                        $host,
                        $node->user,
                        22,
                        $this->keys->privateKeyPath(),
                        $this->knownHosts->path(),
                        commandTimeout: $this->deadline->cap(30.0),
                    ),
                    new RemoteCommand(
                        [
                            'bash',
                            '-seu',
                            '--',
                            $repository,
                            $checkout['path'],
                            $checkout['root'],
                            $checkout['user'],
                            $checkout['slug'],
                            $checkout['instance'],
                            $checkout['mode'],
                            $checkout['expected_root'],
                        ],
                        input: 'repository=$1'
                        ."\n"
                        .'checkout=$2'
                        ."\n"
                        .'root=$3'
                        ."\n"
                        .'user=$4'
                        ."\n"
                        .'slug=$5'
                        ."\n"
                        .'instance=$6'
                        ."\n"
                        .'mode=$7'
                        ."\n"
                        .'expected_root=$8'
                        ."\n"
                        .'if [ "$mode" = app-dev ]; then'
                        ."\n"
                        .'  test -z "$slug" && test -z "$instance"'
                        ."\n"
                        .'  test "$root" = "$expected_root"'
                        ."\n"
                        .'  case "$checkout" in "$expected_root"|"$expected_root"/*) ;; *) exit 1 ;; esac'
                        ."\n"
                        .'  if test -d "$checkout" && test ! -L "$checkout" && test "$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" config --get remote.origin.url 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
                        ."\n"
                        .'  exit 0'
                        ."\n"
                        .'fi'
                        ."\n"
                        .'test -n "$user" && test -n "$root" && test -n "$checkout"'
                        ."\n"
                        .'test ! -L "$root"'
                        ."\n"
                        .'if sudo -u "$user" -H -- test -d "$checkout"'
                        .' && sudo -u "$user" -H -- test ! -L "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- realpath -e "$checkout")" = "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- stat -c %U "$checkout")" = "$user"'
                        .' && test "$(sudo -u "$user" -H -- git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --show-toplevel 2>/dev/null)" = "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" config --get remote.origin.url 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
                        ."\n",
                    ),
                );
            } catch (\Throwable) {
                $failedInstanceIds[] = (int) $checkout['instance_id'];

                continue;
            }

            if (
                ! $result->succeeded()
                || $result->truncated
                || ! in_array(needle: $result->stdout, haystack: ["1\n", "0\n"], strict: true)
            ) {
                $failedInstanceIds[] = (int) $checkout['instance_id'];

                continue;
            }

            if ($result->stdout !== "1\n") {
                $mismatchingInstanceIds[] = (int) $checkout['instance_id'];
                $match = false;
            }
        }

        return new ProjectInspectionData(count($checkouts), $match, $mismatchingInstanceIds, $failedInstanceIds);
    }

    /** Resolves the single managed apps root for development checkouts. */
    private function developmentRoot(Node $node, ManagedUserAccount $account): string
    {
        try {
            $appsRoot = $this->storageRoots
                ->resolveApps($this->nodeSettings->fromStored($node->settings), $account);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return $appsRoot->value;
    }
}
