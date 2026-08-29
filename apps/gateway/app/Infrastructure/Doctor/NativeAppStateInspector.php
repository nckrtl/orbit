<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\AppInspectionData;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Instances\CertificateMode;
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
    ) {}

    public function inspect(App $app, Node $node): AppInspectionData
    {
        if (! is_string($node->wireguard_address) || $node->wireguard_address === '') {
            throw new DoctorInspectionException;
        }

        try {
            $repository = GitRepositoryOrigin::validate($app->repository_url);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        /** @var list<array{path: string, root: string, user: string, slug: string, instance: string}> $checkouts */
        $checkouts = [];
        $instances = $app
            ->instances()
            ->where('node_id', $node->id)
            ->with('workspaces')
            ->get();
        foreach ($instances as $instance) {
            $production = $instance->certificate_mode === CertificateMode::Acme;
            $checkouts[] = [
                'path' => $instance->checkout_path,
                'root' => $production ? "/var/www/{$app->slug}" : '/home/orbit',
                'user' => $production ? "orbit-{$app->slug}" : 'orbit',
                'slug' => $production ? $app->slug : '',
                'instance' => $production ? $instance->name : '',
            ];
            $workspaces = $instance->workspaces;
            foreach ($workspaces as $workspace) {
                $checkouts[] = [
                    'path' => $workspace->checkout_path,
                    'root' => '/home/orbit',
                    'user' => 'orbit',
                    'slug' => '',
                    'instance' => '',
                ];
            }
        }
        $match = true;
        foreach ($checkouts as $checkout) {
            try {
                $result = $this->ssh->execute(
                    new SshConnection(
                        $node->wireguard_address,
                        'orbit',
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
                        .'if [ "$user" = orbit ]; then'
                        ."\n"
                        .'  test -z "$slug" && test -z "$instance"'
                        ."\n"
                        .'  test "$root" = /home/orbit'
                        ."\n"
                        .'  case "$checkout" in /home/orbit|/home/orbit/*) ;; *) exit 1 ;; esac'
                        ."\n"
                        .'  if test -d "$checkout" && test ! -L "$checkout" && test "$(git -C "$checkout" remote get-url origin 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
                        ."\n"
                        .'  exit 0'
                        ."\n"
                        .'fi'
                        ."\n"
                        .'test "$user" = "orbit-$slug"'
                        ."\n"
                        .'test "$root" = "/var/www/$slug"'
                        ."\n"
                        .'test "$checkout" = "$root/$instance"'
                        ."\n"
                        .'test ! -L "$root"'
                        ."\n"
                        .'if sudo -u "$user" -H -- test -d "$checkout"'
                        .' && sudo -u "$user" -H -- test ! -L "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- realpath -e "$checkout")" = "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- stat -c %U "$checkout")" = "$user"'
                        .' && test "$(sudo -u "$user" -H -- git -C "$checkout" rev-parse --show-toplevel 2>/dev/null)" = "$checkout"'
                        .' && test "$(sudo -u "$user" -H -- git -C "$checkout" remote get-url origin 2>/dev/null)" = "$repository"; then printf "1\\n"; else printf "0\\n"; fi'
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
}
