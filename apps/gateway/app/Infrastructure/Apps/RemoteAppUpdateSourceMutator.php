<?php

declare(strict_types=1);

namespace App\Infrastructure\Apps;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteAppUpdateSourceMutator implements AppUpdateSourceMutator
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $this->run(
                $checkout,
                [$checkout->checkout_path, $currentUrl, $proposedUrl],
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
                    git -C "$path" ls-remote --heads -- "$proposed" >/dev/null
                    BASH,
                'app-update-repository-preflight',
                'app.repository_preflight_failed',
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array
    {
        $byPath = [];

        foreach ($evidence as $row) {
            $byPath[$row['path']] = $row;
        }

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');

            if (($byPath[$path]['mutated'] ?? false) === true) {
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

            $byPath[$path] = [
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => true,
            ];
        }

        return array_values($byPath);
    }

    public function restoreOrigins(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            if (($mutation['mutated'] ?? false) !== true) {
                continue;
            }

            $checkout = AppInstance::query()
                ->where('checkout_path', $mutation['path'])
                ->first();

            if (! $checkout instanceof AppInstance) {
                continue;
            }

            $this->run(
                $checkout,
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
                git -C "$path" fetch --prune -- origin
                git -C "$path" show-ref --verify --quiet "refs/remotes/origin/$branch"
                BASH,
            'app-update-default-branch-preflight',
            'app.source_switch_failed',
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

        foreach ($checkouts as $checkout) {
            $unique[rtrim($checkout->checkout_path, '/')] = $checkout;
        }

        return array_values($unique);
    }

    /** @param list<string> $arguments */
    private function run(
        AppInstance $instance,
        array $arguments,
        string $script,
        string $step,
        string $errorCode,
    ): void {
        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', ...$arguments],
                    input: $script,
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
