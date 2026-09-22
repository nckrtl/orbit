<?php

declare(strict_types=1);

namespace App\Infrastructure\Apps;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\Apps\AppRepositoryUpdatePlanner;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;

final readonly class RemoteAppUpdateSourceMutator implements AppUpdateSourceMutator
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private RepositoryReadAccess $access,
    ) {}

    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        $read = $this->access->for($proposedUrl);

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $this->run(
                $checkout,
                [rtrim($checkout->checkout_path, '/'), $currentUrl, $proposedUrl],
                <<<'BASH'
                    path=$1
                    current=$2
                    proposed=$3
                    test -d "$path"
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    origin=$(git -C "$path" remote get-url origin)
                    test "$origin" = "$current"
                    git -C "$path" rev-parse --verify --quiet HEAD >/dev/null
                    git_read git -C "$path" ls-remote --heads -- "$proposed" >/dev/null
                    BASH,
                'app-update-repository-preflight',
                'app.repository_preflight_failed',
                read: $read,
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array
    {
        $checkouts = $this->uniqueCheckouts($checkouts);
        $byId = $this->ownedEvidence($checkouts, $evidence);

        foreach ($checkouts as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');

            if (($byId[$checkout->id]['mutated'] ?? false) === true) {
                continue;
            }

            $this->run(
                $checkout,
                [$path, $newUrl],
                <<<'BASH'
                    path=$1
                    url=$2
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    git -C "$path" remote set-url origin -- "$url"
                    git -C "$path" remote get-url origin
                    BASH,
                'app-update-repository-origin',
                'app.repository_origin_failed',
            );

            $byId[$checkout->id] = [
                'app_id' => $checkout->app_id,
                'instance_id' => $checkout->id,
                'node_id' => $checkout->node_id,
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => true,
            ];
        }

        return array_values($byId);
    }

    public function restoreOrigins(OrbitApp $app, array $mutations): void
    {
        $inventory = new AppRepositoryUpdatePlanner()->inventory($app->appInstances()->with('node.roles')->get());
        $checkouts = $this->uniqueCheckouts($inventory['checkouts']);
        $byId = array_column($checkouts, null, 'id');

        foreach ($this->ownedEvidence($checkouts, $mutations) as $mutation) {
            if ($mutation['mutated'] !== true) {
                continue;
            }

            $this->run(
                $byId[$mutation['instance_id']],
                [$mutation['path'], $mutation['previous_url']],
                <<<'BASH'
                    path=$1
                    url=$2
                    git -C "$path" remote set-url origin -- "$url"
                    BASH,
                'app-update-repository-restore',
                'app.repository_origin_failed',
            );
        }
    }

    public function preflightDefaultBranch(AppInstance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch],
            <<<'BASH'
                path=$1
                branch=$2
                test -d "$path"
                git -C "$path" rev-parse --is-inside-work-tree >/dev/null
                git_read git -C "$path" fetch --prune -- origin
                git -C "$path" show-ref --verify --quiet "refs/remotes/origin/$branch"
                BASH,
            'app-update-default-branch-preflight',
            'app.source_switch_failed',
            read: $this->access->for($instance->loadMissing('app')->app->repository_url),
        );
    }

    public function switchDefaultBranch(AppInstance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout --track -B "$branch" "origin/$branch"
                BASH,
            'app-update-default-branch-switch',
            'app.source_switch_failed',
        );
    }

    public function restoreDefaultBranch(AppInstance $instance, string $previousBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $previousBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout -- "$branch"
                BASH,
            'app-update-default-branch-restore',
            'app.source_switch_failed',
        );
    }

    /**
     * @param  list<AppInstance>  $checkouts
     * @return list<AppInstance>
     */
    private function uniqueCheckouts(array $checkouts): array
    {
        $unique = [];
        $current = AppInstance::query()
            ->with('node.roles')
            ->whereKey(array_column($checkouts, 'id'))
            ->get()
            ->keyBy('id');

        foreach ($checkouts as $checkout) {
            $fresh = $current->get($checkout->id);

            if (
                ! $fresh instanceof AppInstance
                || $fresh->app_id !== $checkout->app_id
                || $fresh->node_id !== $checkout->node_id
                || rtrim($fresh->checkout_path, '/') !== rtrim($checkout->checkout_path, '/')
                || $fresh->source_layout !== AppInstanceSourceLayout::Checkout->value
                || $fresh->placedOnAppProd()
            ) {
                $this->refuseOwner();
            }

            $identity = $fresh->node_id.':'.rtrim($fresh->checkout_path, '/');

            if (isset($unique[$identity]) && $unique[$identity]->id !== $fresh->id) {
                $this->refuseOwner();
            }

            $unique[$identity] = $fresh;
        }

        return array_values($unique);
    }

    /**
     * @param  list<AppInstance>  $checkouts
     * @param  list<array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool}>  $evidence
     * @return array<int, array{app_id: int, instance_id: int, node_id: int, path: string, previous_url: string, current_url: string, mutated: bool}>
     */
    private function ownedEvidence(array $checkouts, array $evidence): array
    {
        $owned = [];

        foreach ($evidence as $row) {
            $legacy = ! array_key_exists('app_id', $row)
                && ! array_key_exists('instance_id', $row)
                && ! array_key_exists('node_id', $row);
            $matches = array_values(array_filter(
                $checkouts,
                static fn (AppInstance $checkout): bool => rtrim($checkout->checkout_path, '/') === rtrim($row['path'], '/')
                    && ($legacy || (
                        ($row['app_id'] ?? null) === $checkout->app_id
                        && ($row['instance_id'] ?? null) === $checkout->id
                        && ($row['node_id'] ?? null) === $checkout->node_id
                    )),
            ));

            if (count($matches) !== 1) {
                $this->refuseOwner();
            }

            $checkout = $matches[0];
            $normalized = [
                'app_id' => $checkout->app_id,
                'instance_id' => $checkout->id,
                'node_id' => $checkout->node_id,
                'path' => rtrim($checkout->checkout_path, '/'),
                'previous_url' => $row['previous_url'],
                'current_url' => $row['current_url'],
                'mutated' => $row['mutated'],
            ];

            if (isset($owned[$checkout->id]) && $owned[$checkout->id] !== $normalized) {
                $this->refuseOwner();
            }

            $owned[$checkout->id] = $normalized;
        }

        return $owned;
    }

    private function refuseOwner(): never
    {
        throw new ResourceOperationException(
            errorCode: 'app.repository_origin_owner_changed',
            message: 'Repository origin evidence does not identify one current development checkout owner.',
            status: 409,
        );
    }

    /** @param list<string> $arguments */
    private function run(
        AppInstance $instance,
        array $arguments,
        string $script,
        string $step,
        string $errorCode,
        ?GitReadEnvironment $read = null,
    ): void {
        $readScript = $read instanceof GitReadEnvironment
            ? GitReadScript::for($read, $script)
            : null;

        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', ...$arguments],
                    input: $readScript === null ? $script : $readScript->input,
                    protectedInput: $readScript?->protectedInput,
                ),
                step: $step,
                errorCode: $errorCode,
            );
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }
}
