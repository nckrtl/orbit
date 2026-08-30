<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\EffectiveStorageRoots;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class RemoteNodeStorageRootPreparer implements NodeStorageRootPreparer
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void
    {
        $this->run($node, $account, $path, createMissing: false);
    }

    public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void
    {
        $this->run($node, $account, $roots->instance, createMissing: true);
        $this->run($node, $account, $roots->worktree, createMissing: true);
    }

    private function run(
        Node $node,
        ManagedUserAccount $account,
        StoragePath $path,
        bool $createMissing,
    ): void {
        try {
            $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        'bash',
                        '-seu',
                        '--',
                        $path->value,
                        $account->user,
                        $account->group,
                        $account->home,
                        $createMissing ? '1' : '0',
                    ],
                    input: <<<'BASH'
                        root=$1
                        managed_user=$2
                        managed_group=$3
                        managed_home=$4
                        create_missing=$5

                        existing=$root
                        while [ ! -e "$existing" ] && [ ! -L "$existing" ]; do
                            existing=$(dirname "$existing")
                        done

                        test -d "$existing"
                        test ! -L "$existing"
                        test "$(realpath -e "$existing")" = "$existing"

                        current=$existing
                        relative=${root#"$existing"}
                        relative=${relative#/}
                        if [ -n "$relative" ]; then
                            IFS=/ read -r -a segments <<< "$relative"
                            for segment in "${segments[@]}"; do
                                current="$current/$segment"
                                if [ -e "$current" ] || [ -L "$current" ]; then
                                    test -d "$current"
                                    test ! -L "$current"
                                    test "$(realpath -e "$current")" = "$current"
                                    continue
                                fi
                                if [ "$create_missing" != 1 ]; then
                                    exit 1
                                fi
                                install -d -o "$managed_user" -g "$managed_group" -m 0755 -- "$current"
                            done
                        fi

                        test -d "$root"
                        test ! -L "$root"
                        test "$(realpath -e "$root")" = "$root"
                        owner=$(stat -c '%U:%G' "$root")
                        mode=$(stat -c '%a' "$root")
                        if [ "$create_missing" = 1 ] && [ "$owner" = "$managed_user:$managed_group" ] && [ "$mode" = 755 ]; then
                            exit 0
                        fi
                        test "$owner" = "$managed_user:$managed_group"
                        printf '%s\n' "$mode" | grep -Eq '^7[0145][0145]$'
                        BASH,
                ),
                step: 'node-storage-root',
                errorCode: 'node.settings_root_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            throw $exception;
        }
    }
}
