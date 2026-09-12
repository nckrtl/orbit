<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\AppInstanceCloneCandidateInspector;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\CloneCandidateSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use Throwable;

final readonly class RemoteAppInstanceCloneCandidateInspector implements AppInstanceCloneCandidateInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function inspect(AppInstance $candidate, string $targetBranch): CloneCandidateSource
    {
        if (! GitBranchName::isValid($targetBranch)) {
            throw $this->conflict(
                'instance.clone_target_branch_missing',
                'The selected target branch is invalid.',
            );
        }

        $candidate->refresh()->loadMissing(['app', 'node']);
        $node = $candidate->node;
        [$basePath, $executionUser, $configuredBranch, $expectedSource] = $this->identity($candidate, $node);
        $sshUser = $node->user;

        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    host: (string) $node->wireguard_ip,
                    user: $sshUser,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                    commandTimeout: 120.0,
                ),
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $candidate->environment,
                        $basePath,
                        $executionUser,
                        $candidate->app->repository_url,
                        $configuredBranch,
                        $targetBranch,
                        $expectedSource,
                    ],
                    input: <<<'BASH'
                        environment=$1
                        base=$2
                        runtime_user=$3
                        repository=$4
                        configured_branch=$5
                        target_branch=$6
                        expected_source=$7

                        refuse() {
                            printf 'REFUSED\t%s\n' "$1"
                            exit 0
                        }

                        run_as_runtime() {
                            sudo -n -u "$runtime_user" -H -- "$@"
                        }

                        case "$environment" in
                            development)
                                source=$base
                                ;;
                            production)
                                current="$base/current"
                                run_as_runtime test -L "$current" || refuse instance.clone_candidate_release_missing
                                source=$(run_as_runtime realpath -e -- "$current") \
                                    || refuse instance.clone_candidate_release_missing
                                case "$source" in
                                    "$base"/releases/*) ;;
                                    *) refuse instance.clone_candidate_source_invalid ;;
                                esac
                                ;;
                            *) refuse instance.clone_candidate_environment_invalid ;;
                        esac

                        test "$source" = "$expected_source" \
                            || refuse instance.clone_candidate_release_changed

                        run_as_runtime test -d "$source" || refuse instance.clone_candidate_source_invalid
                        run_as_runtime test ! -L "$source" || refuse instance.clone_candidate_source_invalid
                        top=$(run_as_runtime git -C "$source" rev-parse --show-toplevel 2>/dev/null) \
                            || refuse instance.clone_candidate_source_invalid
                        source_real=$(run_as_runtime realpath -e -- "$source") \
                            || refuse instance.clone_candidate_source_invalid
                        test "$top" = "$source_real" || refuse instance.clone_candidate_source_invalid
                        actual_branch=$(run_as_runtime git -C "$source" symbolic-ref --quiet --short HEAD 2>/dev/null) \
                            || refuse instance.clone_candidate_branch_invalid
                        test "$actual_branch" = "$configured_branch" \
                            || refuse instance.clone_candidate_branch_invalid
                        commit=$(run_as_runtime git -C "$source" rev-parse --verify 'HEAD^{commit}' 2>/dev/null) \
                            || refuse instance.clone_candidate_source_invalid
                        case "$commit" in
                            [0-9a-f][0-9a-f][0-9a-f][0-9a-f]*) ;;
                            *) refuse instance.clone_candidate_source_invalid ;;
                        esac
                        test -z "$(run_as_runtime git -C "$source" status --porcelain=v1 --untracked-files=all --ignore-submodules=none)" \
                            || refuse instance.clone_candidate_dirty
                        submodules=$(run_as_runtime git -C "$source" submodule status --recursive 2>/dev/null) \
                            || refuse instance.clone_candidate_dirty
                        if printf '%s\n' "$submodules" | grep -Eq '^[+-U]'; then
                            refuse instance.clone_candidate_dirty
                        fi
                        run_as_runtime git -C "$source" submodule foreach --recursive --quiet \
                            'test -z "$(git status --porcelain=v1 --untracked-files=all --ignore-submodules=none)"' \
                            >/dev/null 2>&1 || refuse instance.clone_candidate_dirty

                        scratch=$(run_as_runtime mktemp -d) || refuse instance.clone_candidate_repository_unavailable
                        cleanup() { run_as_runtime rm -rf -- "$scratch"; }
                        trap cleanup EXIT
                        run_as_runtime git init --quiet --bare "$scratch/repository.git" \
                            || refuse instance.clone_candidate_repository_unavailable
                        run_as_runtime git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --prune \
                            "$repository" '+refs/heads/*:refs/remotes/origin/*' \
                            || refuse instance.clone_candidate_repository_unavailable
                        run_as_runtime git --git-dir="$scratch/repository.git" cat-file -e "$commit^{commit}" \
                            || refuse instance.clone_candidate_commit_unavailable
                        run_as_runtime git --git-dir="$scratch/repository.git" show-ref --verify --quiet \
                            "refs/remotes/origin/$target_branch" \
                            || refuse instance.clone_target_branch_missing

                        printf 'OK\t%s\t%s\n' "$source_real" "$commit"
                        BASH,
                ),
            );
        } catch (Throwable $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_candidate_unavailable',
                message: 'The candidate source could not be inspected.',
                status: 502,
                previous: $exception,
            );
        }

        if (! $result->succeeded() || $result->stderr !== '' || $result->truncated) {
            throw $this->conflict('instance.clone_candidate_unavailable', 'The candidate source could not be inspected.');
        }

        $parts = explode("\t", trim($result->stdout));

        if (count($parts) === 2 && $parts[0] === 'REFUSED') {
            throw $this->conflict($parts[1], 'The candidate is not eligible for cloning.');
        }

        if (
            count($parts) !== 3
            || $parts[0] !== 'OK'
            || $parts[1] === ''
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $parts[2]) !== 1
        ) {
            throw $this->conflict('instance.clone_candidate_unavailable', 'The candidate inspection was invalid.');
        }

        if ($parts[1] !== $expectedSource) {
            throw $this->conflict(
                'instance.clone_candidate_source_invalid',
                'The candidate selected source changed during inspection.',
            );
        }

        return new CloneCandidateSource(
            appInstanceId: $candidate->id,
            environment: $candidate->environment,
            basePath: $parts[1],
            executionUser: $executionUser,
            branch: $configuredBranch,
            commit: $parts[2],
            node: $node,
        );
    }

    /** @return array{string, string, string, string} */
    private function identity(AppInstance $candidate, Node $node): array
    {
        if (
            $candidate->status !== AppInstanceState::Active
            || $candidate->provisioning_step !== 'active'
            || $candidate->migration_required
            || $node->status !== LifecycleStatus::Active
            || $node->platform !== 'linux'
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            throw $this->conflict('instance.clone_candidate_inactive', 'The candidate is not active.');
        }

        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $node->user) !== 1) {
            throw $this->conflict(
                'instance.clone_candidate_source_invalid',
                'The candidate source identity is invalid.',
            );
        }

        if ($candidate->environment === 'development') {
            $basePath = $candidate->checkout_path;
            $executionUser = $node->user;
            $configuredBranch = $candidate->branch;
            $expectedSource = $candidate->checkout_path;
        } elseif ($candidate->environment === 'production') {
            if (! $candidate->usesProductionReleaseLayout()) {
                throw $this->conflict(
                    'instance.clone_candidate_release_missing',
                    'The production candidate has no selected release.',
                );
            }

            $basePath = $candidate->production_home;
            $executionUser = $candidate->production_user;
            $configuredBranch = $candidate->deployment_branch ?? $candidate->branch;
            $expectedSource = $candidate->checkout_path;
        } else {
            throw $this->conflict(
                'instance.clone_candidate_environment_invalid',
                'Only development or production candidates can be cloned.',
            );
        }

        if (
            ! is_string($basePath)
            || ! str_starts_with($basePath, '/')
            || ! is_string($executionUser)
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $executionUser) !== 1
            || ! is_string($configuredBranch)
            || ! GitBranchName::isValid($configuredBranch)
            || ! str_starts_with($expectedSource, '/')
        ) {
            throw $this->conflict('instance.clone_candidate_source_invalid', 'The candidate source identity is invalid.');
        }

        return [$basePath, $executionUser, $configuredBranch, $expectedSource];
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
