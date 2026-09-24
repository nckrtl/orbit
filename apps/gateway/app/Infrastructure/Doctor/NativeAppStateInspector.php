<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\AppInspectionData;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App;
use App\Models\Node;

final readonly class NativeAppStateInspector implements AppStateInspector
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

    public function inspect(App $app, Node $node): AppInspectionData
    {
        $host = $node->wireguard_ip;
        if (! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        try {
            $account = null;
            $repository = GitRepositoryOrigin::validate($app->repository_url);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        /** @var list<array{path: string, root: string, user: string, slug: string, instance: string, mode: string, expected_root: string}> $checkouts */
        $checkouts = [];
        $appInstances = $app
            ->appInstances()
            ->where('node_id', $node->id)
            ->orderBy('id')
            ->get();
        foreach ($appInstances as $appInstance) {
            if ($appInstance->placedOnAppProd()) {
                $home = $appInstance->production_home;
                $user = $appInstance->production_user;
                if (! is_string($home) || $home === '' || ! is_string($user) || $user === '') {
                    throw new DoctorInspectionException;
                }

                $checkouts[] = [
                    'path' => $appInstance->checkout_path,
                    'root' => $home,
                    'user' => $user,
                    'slug' => $app->slug,
                    'instance' => $appInstance->name,
                    'mode' => 'app-prod',
                    'expected_root' => '',
                ];

                continue;
            }

            $account ??= $this->accounts->resolve($node);
            $root = $this->developmentRoot($node, $account, $appInstance->checkout_path);
            $checkouts[] = [
                'path' => $appInstance->checkout_path,
                'root' => $root,
                'user' => $account->user,
                'slug' => '',
                'instance' => '',
                'mode' => 'app-dev',
                'expected_root' => $root,
            ];
        }
        $match = true;
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
                        .'  if test -d "$checkout" && test ! -L "$checkout" && test "$(git -C "$checkout" config --get remote.origin.url 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
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
                        .' && test "$(sudo -u "$user" -H -- git -C "$checkout" rev-parse --show-toplevel 2>/dev/null)" = "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- git -C "$checkout" config --get remote.origin.url 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
                        ."\n",
                    ),
                );
                if (
                    ! $result->succeeded()
                    || $result->truncated
                    || ! in_array(needle: $result->stdout, haystack: ["1\n", "0\n"], strict: true)
                ) {
                    throw new DoctorInspectionException;
                }
                $match = $match && $result->stdout === "1\n";
            } catch (\Throwable) {
                throw new DoctorInspectionException;
            }
        }

        return new AppInspectionData(count($checkouts), $match);
    }

    /**
     * A development checkout lives under the Node's effective apps root, or under the managed
     * user's home when it predates a configured apps root.
     */
    private function developmentRoot(Node $node, ManagedUserAccount $account, string $checkoutPath): string
    {
        try {
            $appsRoot = $this->storageRoots
                ->resolveApps($this->nodeSettings->fromStored($node->settings), $account)
                ->instance;
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        $checkout = StoragePath::tryParse($checkoutPath);

        return $checkout instanceof StoragePath && $checkout->isInside($appsRoot)
            ? $appsRoot->value
            : $account->home;
    }
}
