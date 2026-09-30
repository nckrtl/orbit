<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\DevelopmentInstanceCopyInspection;
use App\Domain\Instances\DevelopmentInstanceCopyResult;
use App\Domain\Instances\InstanceCopyMode;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use Throwable;

final readonly class RemoteDevelopmentInstanceCheckoutCopier implements DevelopmentInstanceCheckoutCopier
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    public function inspect(Instance $source, string $branch): DevelopmentInstanceCopyInspection
    {
        $source->loadMissing(['project', 'node']);
        $paths = $this->sourcePaths($source);
        $recorded = $this->recordedBranch($source);
        GitBranchName::validate($branch);
        $result = $this->execute(
            $source->node,
            ['bash', '-seu', '--', $paths['source'], $recorded, $branch, RuntimeHibernation::coldPath(RuntimeHibernation::key($source->id)), $paths['origin']],
            self::inspectScript(),
        );

        if (! $result->succeeded()) {
            $this->fail('instance.copy_source_unsafe', "Instance [{$source->name}] could not be read for a copy.");
        }

        $lines = $this->lines($result->stdout);

        if (($lines[0] ?? '') === 'refused') {
            $this->fail(
                $this->refusalCode($lines[1] ?? ''),
                "Instance [{$source->name}] cannot be copied.",
            );
        }

        if (($lines[0] ?? '') !== 'ready' || ! $this->commit($lines[1] ?? '')) {
            $this->fail('instance.copy_failed', "Instance [{$source->name}] returned invalid copy evidence.");
        }

        return new DevelopmentInstanceCopyInspection($lines[1]);
    }

    public function copy(
        Instance $source,
        Instance $target,
        string $branch,
        string $expectedHead,
        string $occupiedCode,
    ): DevelopmentInstanceCopyResult {
        $source->loadMissing(['project', 'node']);
        $target->loadMissing(['project', 'node']);
        $paths = $this->copyPaths($source, $target);
        GitBranchName::validate($branch);

        if (! $this->commit($expectedHead)) {
            $this->fail('instance.copy_failed', "Instance [{$target->name}] has no source commit to copy.");
        }

        $placement = $this->execute(
            $target->node,
            ['bash', '-seu', '--', $paths['apps'], $paths['destination'], $paths['marker']],
            self::placementScript(),
        );

        if (! $placement->succeeded()) {
            $this->fail('instance.copy_failed', "Instance [{$target->name}] destination could not be prepared.");
        }

        $placementStatus = $this->lines($placement->stdout)[0] ?? '';

        if ($placementStatus === 'occupied') {
            $this->fail($occupiedCode, "Instance destination [{$paths['destination']}] is already in use.");
        }

        if ($placementStatus === 'conflict' || $placementStatus === 'invalid' || ! in_array($placementStatus, ['ready', 'reuse'], true)) {
            $this->fail('instance.placement_conflict', "Instance [{$target->name}] copy placement changed.");
        }

        if ($placementStatus === 'reuse') {
            $this->clearOwned($target->node, $paths, keepMarker: true);
        }

        try {
            $sync = $this->execute($target->node, ['sync', '-f', $paths['apps']]);

            if (! $sync->succeeded()) {
                // A sync failure only makes a dirty-block reflink less likely. The copy still runs.
            }

            $mode = $this->copyTree($target->node, $paths);
            $head = $this->pointBranch($source, $target, $paths, $branch, $expectedHead);
        } catch (Throwable $exception) {
            try {
                $this->discardOwned($target->node, $paths);
            } catch (Throwable) {
                // The marker-guarded discard is the cleanup. The original failure still decides the response.
            }

            throw $this->withCopyStarted($exception);
        }

        return new DevelopmentInstanceCopyResult($mode, $head);
    }

    public function deleteMarker(Instance $target): void
    {
        $target->loadMissing(['project', 'node']);
        $paths = $this->targetPaths($target);
        $result = $this->execute(
            $target->node,
            ['bash', '-seu', '--', $paths['marker'], $paths['destination']],
            self::markerRemovalScript(),
        );

        if (! $result->succeeded()) {
            $this->fail('instance.copy_failed', "Instance [{$target->name}] copy marker could not be removed.");
        }
    }

    public function discardPartial(Instance $target): void
    {
        $target->loadMissing(['project', 'node']);
        $this->discardOwned($target->node, $this->targetPaths($target));
    }

    /** @param array{apps: string, source: string, destination: string, marker: string, origin: string} $paths */
    private function copyTree(Node $node, array $paths): string
    {
        $reflink = $this->attemptCopy($node, $paths, reflink: true, retried: false);

        if ($reflink === 'ok') {
            return InstanceCopyMode::Reflink;
        }

        if ($reflink === 'fallback') {
            $this->clearOwned($node, $paths, keepMarker: true);
            $plain = $this->attemptCopy($node, $paths, reflink: false, retried: false);

            if ($plain === 'ok') {
                return InstanceCopyMode::Full;
            }
        }

        $this->discardOwned($node, $paths);
        $this->fail('instance.copy_failed', 'The Instance checkout could not be copied.', started: true);
    }

    /**
     * @param  array{apps: string, source: string, destination: string, marker: string, origin: string}  $paths
     * @return 'ok'|'fallback'|'fail'
     */
    private function attemptCopy(Node $node, array $paths, bool $reflink, bool $retried): string
    {
        $arguments = ['env', 'LC_ALL=C', 'cp', '-a'];

        if ($reflink) {
            $arguments[] = '--reflink=always';
        }

        $arguments[] = '--';
        $arguments[] = $paths['source'];
        $arguments[] = $paths['destination'];
        $result = $this->execute($node, $arguments);

        if ($result->succeeded()) {
            return 'ok';
        }

        if (! $retried && $this->resetEnoent($result->stderr, $paths['source'])) {
            $this->clearOwned($node, $paths, keepMarker: true);

            return $this->attemptCopy($node, $paths, $reflink, retried: true);
        }

        if ($reflink && $this->fallbackErrno($result->stderr)) {
            return 'fallback';
        }

        return 'fail';
    }

    /**
     * @param  array{apps: string, source: string, destination: string, marker: string, origin: string}  $paths
     */
    private function pointBranch(Instance $source, Instance $target, array $paths, string $branch, string $expectedHead): string
    {
        $result = $this->execute(
            $target->node,
            ['bash', '-seu', '--', $paths['source'], $paths['destination'], $branch, $expectedHead, $paths['origin']],
            self::branchScript(),
        );

        if (! $result->succeeded()) {
            $this->discardOwned($target->node, $paths);
            $this->fail('instance.copy_failed', "Instance [{$target->name}] branch could not be created.", started: true);
        }

        $lines = $this->lines($result->stdout);

        if (($lines[0] ?? '') === 'changed') {
            $this->discardOwned($target->node, $paths);
            $this->fail('instance.copy_source_changed', "Instance [{$source->name}] HEAD moved during the copy.", started: true);
        }

        if (($lines[0] ?? '') !== 'ready' || ($lines[1] ?? '') !== $expectedHead) {
            $this->discardOwned($target->node, $paths);
            $this->fail('instance.copy_failed', "Instance [{$target->name}] branch evidence is invalid.", started: true);
        }

        return $lines[1];
    }

    /** @param array{apps: string, source: string, destination: string, marker: string, origin: string} $paths */
    private function clearOwned(Node $node, array $paths, bool $keepMarker): void
    {
        $result = $this->execute(
            $node,
            ['bash', '-seu', '--', $paths['marker'], $paths['destination'], $paths['apps'], $keepMarker ? '1' : '0'],
            self::discardScript(),
        );

        if (! $result->succeeded()) {
            $this->fail('instance.copy_failed', 'The partial Instance copy could not be removed.', started: true);
        }
    }

    /** @param array{apps: string, source: string, destination: string, marker: string, origin: string} $paths */
    private function discardOwned(Node $node, array $paths): void
    {
        $this->clearOwned($node, $paths, keepMarker: false);
    }

    /**
     * @return array{apps: string, source: string, destination: string, marker: string, origin: string}
     */
    private function sourcePaths(Instance $source): array
    {
        $paths = $this->targetPaths($source);
        $paths['source'] = $paths['destination'];

        return $paths;
    }

    /**
     * @return array{apps: string, source: string, destination: string, marker: string, origin: string}
     */
    private function copyPaths(Instance $source, Instance $target): array
    {
        $sourcePaths = $this->sourcePaths($source);
        $targetPaths = $this->targetPaths($target);

        if ($source->node_id !== $target->node_id || $sourcePaths['source'] === $targetPaths['destination']) {
            $this->fail('instance.copy_failed', "Instance [{$target->name}] cannot be copied onto its source path.");
        }

        if ($source->source_layout !== InstanceSourceLayout::Checkout->value) {
            $this->fail('instance.copy_source_layout_invalid', "Instance [{$source->name}] is not an independent checkout.");
        }

        $targetPaths['source'] = $sourcePaths['source'];
        $targetPaths['origin'] = $sourcePaths['origin'];

        return $targetPaths;
    }

    /**
     * @return array{apps: string, source: string, destination: string, marker: string, origin: string}
     */
    private function targetPaths(Instance $target): array
    {
        $target->loadMissing(['project', 'node']);
        $node = $target->node;
        $account = $this->accounts->resolve($node);
        $apps = $this->storageRoots->resolveApps($this->nodeSettings->fromStored($node->settings), $account);
        $destination = StoragePath::parse($target->checkout_path);

        if (! $destination->isInside($apps)) {
            $this->fail('instance.copy_failed', "Instance [{$target->name}] copy paths are not inside the apps root.");
        }

        return [
            'apps' => $apps->value,
            'source' => $destination->value,
            'destination' => $destination->value,
            'marker' => $apps->append('.orbit', 'copies', 'instance-'.$target->id)->value,
            'origin' => GitRepositoryOrigin::validate($target->project->repository_url),
        ];
    }

    private function recordedBranch(Instance $source): string
    {
        $branch = $source->branch;

        if (! is_string($branch) || ! GitBranchName::isValid($branch)) {
            $this->fail('instance.copy_source_branch_invalid', "Instance [{$source->name}] is not on a recorded branch.");
        }

        return $branch;
    }

    /** @param non-empty-list<string> $arguments */
    private function execute(Node $node, array $arguments, ?string $input = null): CommandResult
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            $this->fail('instance.copy_failed', "Node [{$node->name}] has no WireGuard address.");
        }

        return $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 900.0,
            ),
            new RemoteCommand($arguments, $input),
        );
    }

    /** @return list<string> */
    private function lines(string $stdout): array
    {
        $lines = preg_split('/\R/', trim($stdout));

        return is_array($lines) ? $lines : [];
    }

    private function commit(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $value) === 1;
    }

    private function refusalCode(string $code): string
    {
        $allowed = [
            'instance.copy_source_cold',
            'instance.copy_source_layout_invalid',
            'instance.copy_source_unsafe',
            'instance.copy_source_branch_invalid',
            'instance.copy_source_dirty',
            'instance.copy_branch_diverged',
        ];

        return in_array($code, $allowed, true) ? $code : 'instance.copy_source_unsafe';
    }

    private function fallbackErrno(string $stderr): bool
    {
        $lines = $this->errorLines($stderr);

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            $matched = array_any([
                'Operation not supported',
                'Invalid cross-device link',
                'Resource temporarily unavailable',
                'Invalid argument',
            ], fn (string $needle): bool => str_contains($line, $needle));

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function resetEnoent(string $stderr, string $checkout): bool
    {
        $lines = $this->errorLines($stderr);

        if ($lines === []) {
            return false;
        }

        $prefixes = [
            $checkout.'/public/hot',
            $checkout.'/storage/logs/',
            $checkout.'/storage/framework/cache/',
            $checkout.'/storage/framework/sessions/',
            $checkout.'/storage/framework/views/',
            $checkout.'/node_modules/.vite/',
            $checkout.'/node_modules/.cache/',
        ];

        foreach ($lines as $line) {
            if (! str_contains($line, 'No such file or directory')) {
                return false;
            }

            preg_match_all('#(/[^\s\'"]+)#', $line, $matches);
            $paths = $matches[1];

            if ($paths === []) {
                return false;
            }

            foreach ($paths as $path) {
                if (! $this->underReset(rtrim($path, ':'), $prefixes)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $prefixes */
    private function underReset(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_ends_with($prefix, '/')) {
                if (str_starts_with($path, $prefix)) {
                    return true;
                }

                continue;
            }

            if ($path === $prefix) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function errorLines(string $stderr): array
    {
        $lines = preg_split('/\R/', $stderr);

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function withCopyStarted(Throwable $exception): ResourceOperationException
    {
        if ($exception instanceof ResourceOperationException) {
            if (($exception->details['copy_started'] ?? '') === '1') {
                return $exception;
            }

            return new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: $exception->status,
                previous: $exception,
                details: [...$exception->details, 'copy_started' => '1'],
            );
        }

        return new ResourceOperationException(
            errorCode: 'instance.copy_failed',
            message: 'The Instance checkout could not be copied.',
            status: 409,
            previous: $exception,
            details: ['copy_started' => '1'],
        );
    }

    private function fail(string $code, string $message, bool $started = false): never
    {
        throw new ResourceOperationException(
            errorCode: $code,
            message: $message,
            status: 409,
            details: $started ? ['copy_started' => '1'] : [],
        );
    }

    private static function inspectScript(): string
    {
        return <<<'BASH'
            source=$1
            recorded_branch=$2
            target_branch=$3
            cold=$4
            origin=$5

            refuse() {
                printf 'refused\n%s\n' "$1"
                exit 0
            }

            if [ -e "$cold" ] || [ -L "$cold" ]; then
                refuse instance.copy_source_cold
            fi
            if [ ! -d "$source" ] || [ -L "$source" ]; then
                refuse instance.copy_source_unsafe
            fi
            if [ -L "$source/.git" ] || [ -f "$source/.git" ]; then
                refuse instance.copy_source_layout_invalid
            fi
            if [ ! -d "$source/.git" ]; then
                refuse instance.copy_source_unsafe
            fi
            if git -C "$source" config --get core.worktree >/dev/null 2>&1; then
                refuse instance.copy_source_unsafe
            fi
            alternates=$(git -C "$source" rev-parse --path-format=absolute --git-path objects/info/alternates)
            if [ -f "$alternates" ] && [ ! -L "$alternates" ] && [ -s "$alternates" ]; then
                refuse instance.copy_source_unsafe
            fi
            common=$(git -C "$source" rev-parse --path-format=absolute --git-common-dir)
            gitdir=$(git -C "$source" rev-parse --absolute-git-dir)
            if [ "$common" != "$source/.git" ] || [ "$gitdir" != "$source/.git" ]; then
                refuse instance.copy_source_layout_invalid
            fi
            worktrees=$(git -C "$source" worktree list --porcelain | grep -c '^worktree ' || true)
            if [ "$worktrees" != 1 ]; then
                refuse instance.copy_source_layout_invalid
            fi
            if ! head_branch=$(git -C "$source" symbolic-ref --short HEAD 2>/dev/null); then
                refuse instance.copy_source_branch_invalid
            fi
            if [ "$head_branch" != "$recorded_branch" ]; then
                refuse instance.copy_source_branch_invalid
            fi
            dirty=$(git -C "$source" status --porcelain=v1 --untracked-files=no)
            if [ -n "$dirty" ]; then
                refuse instance.copy_source_dirty
            fi
            head=$(git -C "$source" rev-parse --verify HEAD^{commit})
            for ref in "refs/heads/$target_branch" "refs/remotes/origin/$target_branch"; do
                if git -C "$source" show-ref --verify --quiet "$ref"; then
                    tip=$(git -C "$source" rev-parse --verify "$ref^{commit}")
                    if [ "$tip" != "$head" ]; then
                        refuse instance.copy_branch_diverged
                    fi
                fi
            done
            actual_origin=$(git -C "$source" config --get remote.origin.url || true)
            if [ "$actual_origin" != "$origin" ]; then
                refuse instance.copy_source_unsafe
            fi
            printf 'ready\n%s\n' "$head"
            BASH;
    }

    private static function placementScript(): string
    {
        return <<<'BASH'
            apps=$1
            dest=$2
            marker=$3

            case "$dest" in
                "$apps"/*) ;;
                *) printf 'invalid\n'; exit 0 ;;
            esac
            case "$marker" in
                "$apps"/*) ;;
                *) printf 'invalid\n'; exit 0 ;;
            esac
            case "$marker" in
                "$dest"|"$dest"/*) printf 'invalid\n'; exit 0 ;;
            esac

            marker_matches() {
                [ -f "$marker" ] && [ ! -L "$marker" ] || return 1
                IFS= read -r recorded < "$marker"
                [ "$recorded" = "$dest" ]
            }

            if [ -e "$dest" ] || [ -L "$dest" ]; then
                if marker_matches; then
                    printf 'reuse\n'
                    exit 0
                fi
                printf 'occupied\n'
                exit 0
            fi
            if [ -e "$marker" ] || [ -L "$marker" ]; then
                if marker_matches; then
                    printf 'reuse\n'
                    exit 0
                fi
                printf 'conflict\n'
                exit 0
            fi
            install -d -m 0755 -- "$(dirname "$dest")" "$(dirname "$marker")"
            printf '%s\n' "$dest" > "$marker"
            printf 'ready\n'
            BASH;
    }

    private static function discardScript(): string
    {
        return <<<'BASH'
            marker=$1
            dest=$2
            apps=$3
            keep_marker=$4

            [ -f "$marker" ] && [ ! -L "$marker" ]
            IFS= read -r recorded < "$marker"
            [ "$recorded" = "$dest" ]
            case "$dest" in
                "$apps"/*) ;;
                *) exit 1 ;;
            esac
            [ "$dest" != "$apps" ]
            if [ -e "$dest" ] || [ -L "$dest" ]; then
                rm -rf -- "$dest"
            fi
            if [ "$keep_marker" != 1 ]; then
                rm -f -- "$marker"
            fi
            BASH;
    }

    private static function markerRemovalScript(): string
    {
        return <<<'BASH'
            marker=$1
            dest=$2

            if [ ! -f "$marker" ] || [ -L "$marker" ]; then
                exit 0
            fi
            IFS= read -r recorded < "$marker"
            if [ "$recorded" = "$dest" ]; then
                rm -f -- "$marker"
            fi
            BASH;
    }

    private static function branchScript(): string
    {
        return <<<'BASH'
            source=$1
            dest=$2
            branch=$3
            expected=$4
            origin=$5

            [ -d "$dest" ] && [ ! -L "$dest" ]
            [ -d "$dest/.git" ] && [ ! -L "$dest/.git" ]
            source_head=$(git -C "$source" rev-parse --verify HEAD^{commit})
            copy_head=$(git -C "$dest" rev-parse --verify HEAD^{commit})
            if [ "$source_head" != "$expected" ] || [ "$copy_head" != "$expected" ]; then
                printf 'changed\n'
                exit 0
            fi
            git -C "$dest" checkout --quiet --force -B "$branch" "$copy_head"
            test "$(git -C "$dest" symbolic-ref --short HEAD)" = "$branch"
            test "$(git -C "$dest" rev-parse --verify HEAD^{commit})" = "$copy_head"
            test "$(git -C "$dest" config --get remote.origin.url)" = "$origin"
            test "$(git -C "$source" rev-parse --verify HEAD^{commit})" = "$expected"
            printf 'ready\n%s\n' "$copy_head"
            BASH;
    }
}
