<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class RemoteRegistrationSourceManager implements RegistrationSourceManager
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array
    {
        $account = $this->account($node);
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'python3',
                    '-c',
                    self::inspectionScript(),
                    $sourcePath,
                    $includeWorktrees ? '1' : '0',
                    $account->user,
                    $account->group,
                ],
            ),
            step: 'registration-source-inspect',
            errorCode: 'instance.source_invalid',
            commandTimeout: 60,
        );

        try {
            $rows = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->invalidSource($exception);
        }

        if (! is_array($rows) || $rows === []) {
            throw $this->invalidSource();
        }

        $facts = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw $this->invalidSource();
            }

            $facts[] = $this->facts($row);
        }

        $repositoryIdentities = array_unique(array_map(
            static fn (RegistrationSourceFacts $fact): string => $fact->repositoryIdentity,
            $facts,
        ));

        if (count($repositoryIdentities) !== 1) {
            throw $this->invalidSource();
        }

        return $facts;
    }

    public function relocate(AppInstance $appInstance, RegistrationSourceFacts $facts): void
    {
        $this->relocateSet([['appInstance' => $appInstance, 'facts' => $facts]]);
    }

    public function validateRetained(
        Node $node,
        RegistrationSourceFacts $facts,
        string $authoritativePath,
    ): void {
        try {
            $actual = $this->inspect($node, $authoritativePath, false);
        } catch (\Throwable $exception) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained authoritative registration source no longer matches its verified Git identity.',
                409,
                previous: $exception,
            );
        }

        if (
            count($actual) !== 1
            || $actual[0]->path !== $authoritativePath
            || $actual[0]->layout !== $facts->layout
            || $actual[0]->repositoryIdentity !== $facts->repositoryIdentity
        ) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained authoritative registration source no longer matches its verified Git identity.',
                409,
            );
        }
    }

    public function validateRelocationRecovery(
        Node $node,
        RegistrationSourceFacts $facts,
        string $candidatePath,
    ): void {
        try {
            $actual = $this->inspect($node, $candidatePath, false);
        } catch (\Throwable $exception) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained relocation path no longer matches its preserved source state.',
                409,
                previous: $exception,
            );
        }

        if (
            count($actual) !== 1
            || $actual[0]->path !== $candidatePath
            || $actual[0]->layout !== $facts->layout
            || $actual[0]->repositoryIdentity !== $facts->repositoryIdentity
            || $actual[0]->branch !== $facts->branch
            || $actual[0]->detached !== $facts->detached
            || $actual[0]->commit !== $facts->commit
            || $actual[0]->sourceDigest !== $facts->sourceDigest
        ) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained relocation path no longer matches its preserved source state.',
                409,
            );
        }
    }

    public function relocateSet(array $members): void
    {
        if ($members === []) {
            throw new \InvalidArgumentException('A registration source set cannot be empty.');
        }

        $node = $members[0]['appInstance']->node;
        $payload = [];
        $relocated = 0;
        $cleanupReady = 0;

        foreach ($members as $member) {
            $instance = $member['appInstance'];
            $facts = $member['facts'];
            $receipt = $instance->registration_relocation_receipt;

            if ($receipt !== null && (
                ($receipt['source'] ?? null) !== $facts->path
                || ($receipt['destination'] ?? null) !== $instance->checkout_path
            )) {
                if (
                    ($receipt['source'] ?? null) !== $instance->checkout_path
                    || ($receipt['destination'] ?? null) !== $facts->path
                    || $instance->registration_relocation_state !== 'relocated'
                    || $instance->registration_authoritative_path !== $facts->path
                ) {
                    throw new ResourceOperationException('instance.registration_evidence_invalid', 'Registration relocation ownership names a different operation.', 409);
                }

                $receipt = null;
            }

            if ($instance->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.source_conflict',
                    'Registration sources must share one Node.',
                    409,
                );
            }

            $payload[] = [
                'id' => $instance->id,
                'request' => $instance->registration_request_id,
                'receipt' => $receipt,
                'source' => $facts->path,
                'destination' => $instance->checkout_path,
                'layout' => $facts->layout->value,
                'commit' => $facts->commit,
                'branch' => $facts->branch,
                'detached' => $facts->detached,
                'digest' => $facts->sourceDigest,
                'common' => $facts->commonRepositoryPath,
                'worktrees' => $facts->worktreePaths,
                'source_device' => $instance->registration_source_device,
                'source_inode' => $instance->registration_source_inode,
            ];

            if (
                $instance->registration_relocation_state === 'relocated'
                && $instance->registration_authoritative_path === $instance->checkout_path
            ) {
                $relocated++;
            }

            if (
                in_array(
                    $instance->registration_relocation_state,
                    ['destination_verified', 'original_cleanup'],
                    strict: true,
                )
                && $instance->registration_authoritative_path === $instance->checkout_path
                && ($facts->path === $instance->checkout_path
                || is_int($instance->registration_source_device)
                && is_int($instance->registration_source_inode))
            ) {
                $cleanupReady++;
            }
        }

        if ($relocated === count($members)) {
            return;
        }

        if ($relocated !== 0 || $cleanupReady !== 0 && $cleanupReady !== count($members)) {
            throw new ResourceOperationException(
                'instance.registration_evidence_invalid',
                'Registration relocation evidence does not contain one complete source set.',
                409,
            );
        }

        if ($cleanupReady === 0) {
            DB::transaction(static function () use ($members): void {
                foreach ($members as $member) {
                    AppInstance::query()
                        ->whereKey($member['appInstance']->id)
                        ->update([
                            'registration_relocation_state' => 'relocating',
                            'registration_authoritative_path' => $member['facts']->path,
                            'registration_source_device' => null,
                            'registration_source_inode' => null,
                        ]);
                }
            });

            $payload = $this->initializeRelocation($node, $payload, $members);
            $result = $this->runRelocation($node, 'prepare', $payload);
            $identities = $this->preparationIdentities($result->stdout, $members);

            DB::transaction(static function () use ($members, $identities): void {
                foreach ($members as $member) {
                    $instance = $member['appInstance'];
                    $identity = $identities[$instance->id];
                    AppInstance::query()
                        ->whereKey($instance->id)
                        ->update([
                            'registration_relocation_state' => 'destination_verified',
                            'registration_authoritative_path' => $instance->checkout_path,
                            'registration_source_device' => $identity['device'],
                            'registration_source_inode' => $identity['inode'],
                        ]);
                }
            });

            $payload = array_map(
                static function (array $member) use ($identities): array {
                    $identity = $identities[$member['id']];

                    return [
                        ...$member,
                        'source_device' => $identity['device'],
                        'source_inode' => $identity['inode'],
                    ];
                },
                $payload,
            );
        }

        DB::transaction(static function () use ($members): void {
            foreach ($members as $member) {
                AppInstance::query()
                    ->whereKey($member['appInstance']->id)
                    ->update(['registration_relocation_state' => 'original_cleanup']);
            }
        });

        $this->runRelocation($node, 'cleanup', $payload);

        DB::transaction(static function () use ($members): void {
            foreach ($members as $member) {
                AppInstance::query()
                    ->whereKey($member['appInstance']->id)
                    ->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['appInstance']->checkout_path,
                    ]);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<array{appInstance: AppInstance, facts: RegistrationSourceFacts}>  $members
     * @return list<array<string, mixed>>
     */
    private function initializeRelocation(Node $node, array $payload, array $members): array
    {
        $newIntent = false;

        foreach ($payload as $index => $member) {
            if ($member['receipt'] === null) {
                $newIntent = true;
                $payload[$index]['receipt'] = [
                    'version' => 1,
                    'request' => $member['request'],
                    'id' => $member['id'],
                    'source' => $member['source'],
                    'destination' => $member['destination'],
                    'attempt' => (string) Str::uuid(),
                    'parents' => null,
                    'source_parents' => null,
                    'scope' => null,
                    'journal' => null,
                ];
            }
        }

        if ($newIntent) {
            $this->persistRelocationReceipts($payload, $members);
        }

        if (array_all($payload, static fn (array $member): bool => ($member['receipt']['scope'] ?? null) !== null)) {
            return $payload;
        }

        $result = $this->runRelocation($node, 'initialize', $payload);

        try {
            $receipts = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResourceOperationException('instance.registration_incomplete', 'Registration returned invalid relocation ownership.', 502, previous: $exception);
        }

        if (! is_array($receipts) || ! array_is_list($receipts) || count($receipts) !== count($payload)) {
            throw new ResourceOperationException('instance.registration_incomplete', 'Registration returned incomplete relocation ownership.', 502);
        }

        foreach ($payload as $index => $member) {
            $receipt = $receipts[$index];
            $valid = is_array($receipt)
                && count($receipt) === 10
                && ($receipt['version'] ?? null) === 1
                && ($receipt['id'] ?? null) === $member['id']
                && ($receipt['request'] ?? null) === $member['request']
                && ($receipt['source'] ?? null) === $member['source']
                && ($receipt['destination'] ?? null) === $member['destination']
                && ($receipt['attempt'] ?? null) === $member['receipt']['attempt']
                && $this->directoryIdentity($receipt['scope'] ?? null)
                && $this->directoryIdentity($receipt['journal'] ?? null)
                && is_array($receipt['parents'] ?? null)
                && array_is_list($receipt['parents'])
                && $receipt['parents'] !== []
                && count($receipt['parents']) <= 256
                && array_all($receipt['parents'], fn (mixed $identity): bool => $this->directoryIdentity($identity))
                && is_array($receipt['source_parents'] ?? null)
                && array_is_list($receipt['source_parents'])
                && $receipt['source_parents'] !== []
                && count($receipt['source_parents']) <= 256
                && array_all($receipt['source_parents'], fn (mixed $identity): bool => $this->directoryIdentity($identity));

            if (! $valid || $member['receipt']['scope'] !== null && $member['receipt'] !== $receipt) {
                throw new ResourceOperationException('instance.registration_incomplete', 'Registration returned conflicting relocation ownership.', 502);
            }

            $payload[$index]['receipt'] = $receipt;
        }

        $this->persistRelocationReceipts($payload, $members);

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<array{appInstance: AppInstance, facts: RegistrationSourceFacts}>  $members
     */
    private function persistRelocationReceipts(array $payload, array $members): void
    {
        DB::transaction(static function () use ($payload, $members): void {
            foreach ($members as $index => $member) {
                $persisted = AppInstance::query()->whereKey($member['appInstance']->id)->update([
                    'registration_relocation_receipt' => json_encode($payload[$index]['receipt'], JSON_THROW_ON_ERROR),
                ]);

                if ($persisted !== 1) {
                    throw new ResourceOperationException('instance.registration_conflict', 'Registration ownership cannot be retained without its Instance.', 409);
                }
            }
        });

        foreach ($members as $index => $member) {
            $member['appInstance']->setAttribute('registration_relocation_receipt', $payload[$index]['receipt']);
            $member['appInstance']->syncOriginalAttribute('registration_relocation_receipt');
        }

    }

    private function directoryIdentity(mixed $identity): bool
    {
        return is_array($identity)
            && array_is_list($identity)
            && count($identity) === 2
            && array_all($identity, static fn (mixed $part): bool => is_int($part) && $part >= 0);
    }

    /**
     * @param  list<array{appInstance: AppInstance, facts: RegistrationSourceFacts}>  $members
     * @return array<int, array{device: int|null, inode: int|null}>
     */
    private function preparationIdentities(string $output, array $members): array
    {
        try {
            $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResourceOperationException(
                'instance.registration_incomplete',
                'Registration relocation returned invalid cleanup evidence.',
                502,
                previous: $exception,
            );
        }

        if (! is_array($rows) || count($rows) !== count($members)) {
            throw new ResourceOperationException(
                'instance.registration_incomplete',
                'Registration relocation returned incomplete cleanup evidence.',
                502,
            );
        }

        $identities = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? $row['id'] ?? null : null;
            $device = is_array($row) ? $row['source_device'] ?? null : null;
            $inode = is_array($row) ? $row['source_inode'] ?? null : null;

            if (
                ! is_int($id)
                || isset($identities[$id])
                || ($device !== null
                || $inode !== null)
                && (! is_int($device)
                || ! is_int($inode))
            ) {
                throw new ResourceOperationException(
                    'instance.registration_incomplete',
                    'Registration relocation returned invalid cleanup evidence.',
                    502,
                );
            }

            $identities[$id] = ['device' => $device, 'inode' => $inode];
        }

        $expectedIds = array_map(
            static fn (array $member): int => $member['appInstance']->id,
            $members,
        );
        sort($expectedIds);
        $actualIds = array_keys($identities);
        sort($actualIds);

        if ($actualIds !== $expectedIds) {
            throw new ResourceOperationException(
                'instance.registration_incomplete',
                'Registration relocation returned cleanup evidence for a different source set.',
                502,
            );
        }

        return $identities;
    }

    /** @param list<array<string, mixed>> $payload */
    private function runRelocation(Node $node, string $operation, array $payload): CommandResult
    {
        return $this->ssh->execute(
            $node,
            new RemoteCommand([
                'python3',
                '-c',
                self::relocationScript(),
                $operation,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]),
            step: 'registration-source-relocate',
            errorCode: 'instance.registration_incomplete',
            commandTimeout: 900,
        );
    }

    public function restoreOriginal(AppInstance $appInstance, RegistrationSourceFacts $facts): void
    {
        $destination = $appInstance->checkout_path;
        $appInstance->checkout_path = $facts->path;
        $reverse = new RegistrationSourceFacts(
            path: $destination,
            layout: $facts->layout,
            repositoryUrl: $facts->repositoryUrl,
            repositoryIdentity: $facts->repositoryIdentity,
            branch: $facts->branch,
            detached: $facts->detached,
            commit: $facts->commit,
            defaultBranch: $facts->defaultBranch,
            inferredSlug: $facts->inferredSlug,
            inferredRoot: $facts->inferredRoot,
            commonRepositoryPath: $facts->layout === AppInstanceSourceLayout::Checkout
                ? $destination.'/.git'
                : $facts->commonRepositoryPath,
            worktreePaths: array_map(
                static fn (string $path): string => $path === $facts->path ? $destination : $path,
                $facts->worktreePaths,
            ),
            sourceDigest: $facts->sourceDigest,
        );

        $this->relocate($appInstance, $reverse);
    }

    public function prepareLaravelRollback(AppInstance $appInstance): void
    {
        $this->runLaravelReceipt($appInstance, 'prepare');
    }

    public function restoreLaravelConfiguration(AppInstance $appInstance): void
    {
        $this->runLaravelReceipt($appInstance, 'restore');
    }

    public function discardLaravelRollback(AppInstance $appInstance): void
    {
        $this->runLaravelReceipt($appInstance, 'discard');
    }

    private function runLaravelReceipt(AppInstance $appInstance, string $operation): void
    {
        $appInstance->loadMissing('node');
        $stored = AppInstance::query()->findOrFail($appInstance->id);
        $receipt = $stored->registration_laravel_receipt;

        if ($operation === 'prepare' && $stored->registration_completed_at !== null) {
            throw new ResourceOperationException('instance.laravel_rollback_failed', 'Completed registration cannot start a rollback attempt.', 409);
        }

        if ($operation !== 'prepare' && in_array($receipt['outcome'] ?? null, ['restored', 'discarded'], strict: true)) {
            if ($operation === 'restore' && $receipt['outcome'] !== 'restored') {
                throw new ResourceOperationException('instance.laravel_rollback_failed', 'Discarded registration backups cannot be restored.', 409);
            }

            return;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($operation === 'prepare' && ($receipt === null || ($receipt['outcome'] ?? null) === 'restored')) {
                $receipt = [
                    'binding' => [
                        'version' => 1,
                        'request' => $appInstance->registration_request_id,
                        'id' => $appInstance->id,
                        'node' => $appInstance->node_id,
                        'checkout' => $appInstance->checkout_path,
                        'attempt' => (string) Str::uuid(),
                        'parents' => null,
                        'directories' => null,
                        'scope' => null,
                        'journal' => null,
                        'backup' => null,
                    ],
                    'outcome' => 'pending',
                ];
                $this->persistLaravelReceipt($appInstance, $receipt);
            }

            if ($receipt !== null && ($receipt['outcome'] ?? null) === 'pending') {
                $receipt = $this->executeLaravelReceipt($appInstance, 'initialize', $receipt);
                $this->persistLaravelReceipt($appInstance, $receipt);
            }

            $receipt = $this->executeLaravelReceipt($appInstance, $operation, $receipt);
            if ($receipt !== null) {
                $this->persistLaravelReceipt($appInstance, $receipt);
            }

            if ($operation !== 'prepare' || ($receipt['outcome'] ?? null) !== 'restored') {
                return;
            }
        }

        throw new ResourceOperationException('instance.laravel_rollback_failed', 'Registration rollback preparation did not complete.', 409);
    }

    /** @param array<string, mixed>|null $receipt */
    private function persistLaravelReceipt(AppInstance $appInstance, ?array $receipt): void
    {
        $persisted = AppInstance::query()->whereKey($appInstance->id)->update([
            'registration_laravel_receipt' => json_encode($receipt, JSON_THROW_ON_ERROR),
        ]);

        if ($persisted !== 1) {
            throw new ResourceOperationException('instance.laravel_rollback_failed', 'Registration rollback ownership cannot be retained without its Instance.', 409);
        }

        $appInstance->setAttribute('registration_laravel_receipt', $receipt);
        $appInstance->syncOriginalAttribute('registration_laravel_receipt');
    }

    /**
     * @param  array<string, mixed>|null  $receipt
     * @return array<string, mixed>|null
     */
    private function executeLaravelReceipt(AppInstance $appInstance, string $operation, ?array $receipt): ?array
    {
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand([
                'python3',
                '-c',
                self::laravelReceiptScript(),
                $operation,
                json_encode([
                    'id' => $appInstance->id,
                    'node' => $appInstance->node_id,
                    'request' => $appInstance->registration_request_id,
                    'checkout' => $appInstance->checkout_path,
                    'receipt' => $receipt,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]),
            step: 'registration-laravel-rollback',
            errorCode: 'instance.laravel_rollback_failed',
            commandTimeout: 30,
        );

        try {
            $returned = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResourceOperationException('instance.laravel_rollback_failed', 'Registration returned invalid rollback ownership.', 502, previous: $exception);
        }

        if ($receipt === null && $operation === 'discard' && $returned === null) {
            return null;
        }

        $binding = is_array($returned) ? ($returned['binding'] ?? null) : null;
        $expected = $receipt['binding'] ?? null;
        $outcomes = match ($operation) {
            'initialize' => ['initialized'],
            'prepare' => ['ready', 'restored'],
            'restore' => ['restored'],
            'discard' => ['discarded'],
            default => [],
        };
        $valid = is_array($returned) && count($returned) === 2
            && in_array($returned['outcome'] ?? null, $outcomes, strict: true)
            && is_array($binding) && count($binding) === 11 && is_array($expected)
            && array_all(['version', 'request', 'id', 'node', 'checkout', 'attempt'], static fn (string $key): bool => ($binding[$key] ?? null) === ($expected[$key] ?? null))
            && $this->directoryIdentity($binding['scope'] ?? null)
            && $this->directoryIdentity($binding['journal'] ?? null)
            && $this->directoryIdentity($binding['backup'] ?? null)
            && is_array($binding['parents'] ?? null) && array_is_list($binding['parents'])
            && $binding['parents'] !== [] && count($binding['parents']) <= 256
            && array_all($binding['parents'], fn (mixed $identity): bool => $this->directoryIdentity($identity))
            && is_array($binding['directories'] ?? null) && array_is_list($binding['directories'])
            && count($binding['directories']) === 3 && $this->directoryIdentity($binding['directories'][0])
            && array_all($binding['directories'], fn (mixed $identity): bool => $identity === null || $this->directoryIdentity($identity));

        if (! $valid || ($expected['scope'] ?? null) !== null && $binding !== $expected) {
            throw new ResourceOperationException('instance.laravel_rollback_failed', 'Registration returned conflicting rollback ownership.', 502);
        }

        return $returned;
    }

    /** @param array<array-key, mixed> $row */
    private function facts(array $row): RegistrationSourceFacts
    {
        $path = $this->requiredString($row, 'path');
        $layout = AppInstanceSourceLayout::tryFrom($this->requiredString($row, 'layout'));
        $repositoryUrl = GitRepositoryOrigin::validate($this->requiredString($row, 'repository_url'));
        $branch = is_string($row['branch'] ?? null) ? $row['branch'] : null;
        $defaultBranch = is_string($row['default_branch'] ?? null) ? $row['default_branch'] : null;
        $worktreePaths = $row['worktree_paths'] ?? null;

        if (
            ! $layout instanceof AppInstanceSourceLayout
            || $branch !== null
            && ! GitBranchName::isValid($branch)
            || $defaultBranch !== null
            && ! GitBranchName::isValid($defaultBranch)
            || ! is_bool($row['detached'] ?? null)
            || ! is_array($worktreePaths)
            || array_filter($worktreePaths, static fn (mixed $value): bool => ! is_string($value)) !== []
        ) {
            throw $this->invalidSource();
        }

        $commit = $this->requiredString($row, 'commit');
        $digest = $this->requiredString($row, 'source_digest');

        if (
            preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || ($row['detached'] === true) !== ($branch === null)
        ) {
            throw $this->invalidSource();
        }

        /** @var list<string> $worktreePaths */
        return new RegistrationSourceFacts(
            path: $path,
            layout: $layout,
            repositoryUrl: $repositoryUrl,
            repositoryIdentity: GitRepositoryIdentity::derive($repositoryUrl),
            branch: $branch,
            detached: $row['detached'],
            commit: $commit,
            defaultBranch: $defaultBranch,
            inferredSlug: $this->requiredString($row, 'inferred_slug'),
            inferredRoot: is_string($row['inferred_root'] ?? null) ? $row['inferred_root'] : null,
            commonRepositoryPath: $this->requiredString($row, 'common_repository_path'),
            worktreePaths: $worktreePaths,
            sourceDigest: $digest,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '' || preg_match('//u', $value) !== 1) {
            throw $this->invalidSource();
        }

        return $value;
    }

    private function account(Node $node): ManagedUserAccount
    {
        $account = $this->accounts->resolve($node);

        if ($account->user !== $node->user) {
            throw new ResourceOperationException(
                'instance.source_owner_invalid',
                'Registration requires the caller Node managed user.',
                409,
            );
        }

        return $account;
    }

    private function invalidSource(?\Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.source_invalid',
            message: 'The requested path is not a supported Git checkout or worktree.',
            status: 422,
            previous: $previous,
        );
    }

    private static function inspectionScript(): string
    {
        return <<<'PYTHON'
            import hashlib, json, os, pathlib, stat, subprocess, sys

            requested = sys.argv[1]
            include_worktrees = sys.argv[2] == '1'
            managed_user = sys.argv[3]
            managed_group = sys.argv[4]
            git_env = dict(os.environ, GIT_OPTIONAL_LOCKS='0')

            def git(path, *args):
                return subprocess.check_output(['git', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env).decode().strip()

            def digest(path):
                root = pathlib.Path(path)
                h = hashlib.sha256()
                for args in [
                    ('rev-parse', 'HEAD'),
                    ('symbolic-ref', '-q', 'HEAD'),
                    ('status', '--porcelain=v2', '--untracked-files=all'),
                    ('config', '--local', '--null', '--list'),
                    ('show-ref', '--head'),
                ]:
                    try: value = subprocess.check_output(['git', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env)
                    except subprocess.CalledProcessError as error: value = error.output
                    h.update(b'git\0' + b'\0'.join(a.encode() for a in args) + b'\0' + value)
                for current, dirs, files in os.walk(root, topdown=True, followlinks=False):
                    if pathlib.Path(current) == root and '.git' in dirs: dirs.remove('.git')
                    dirs.sort()
                    for name in sorted(dirs + files):
                        entry = pathlib.Path(current) / name
                        relative = entry.relative_to(root).as_posix()
                        if relative == '.git': continue
                        info = entry.lstat()
                        h.update(relative.encode() + b'\0' + str(stat.S_IMODE(info.st_mode)).encode() + b'\0')
                        if entry.is_symlink(): h.update(b'L' + os.readlink(entry).encode())
                        elif entry.is_file():
                            h.update(b'F')
                            with entry.open('rb') as stream:
                                for block in iter(lambda: stream.read(1024 * 1024), b''): h.update(block)
                        else: h.update(b'D')
                return h.hexdigest()

            def safe_metadata(path, directory):
                import grp, pwd
                entry = pathlib.Path(path)
                if entry.is_symlink() or (directory and not entry.is_dir()) or (not directory and not entry.is_file()): raise SystemExit(42)
                info = entry.stat()
                if pwd.getpwuid(info.st_uid).pw_name != managed_user or grp.getgrgid(info.st_gid).gr_name != managed_group: raise SystemExit(42)
                if stat.S_IMODE(info.st_mode) & 0o7002: raise SystemExit(42)

            top = git(requested, 'rev-parse', '--show-toplevel')
            if not os.path.isabs(top) or os.path.realpath(top) != top or not os.path.isdir(top): raise SystemExit(42)
            origin = git(top, 'remote', 'get-url', 'origin')
            common = os.path.realpath(git(top, 'rev-parse', '--path-format=absolute', '--git-common-dir'))
            listing = git(top, 'worktree', 'list', '--porcelain').splitlines()
            worktrees = [line[9:] for line in listing if line.startswith('worktree ')]
            paths = worktrees if include_worktrees else [top]
            try:
                ref = git(top, 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD')
                default_branch = ref.removeprefix('origin/')
            except subprocess.CalledProcessError:
                default_branch = None
            repository_path = origin.split(':', 1)[1] if origin.startswith('git@') else __import__('urllib.parse').parse.urlparse(origin).path
            slug = pathlib.PurePosixPath(repository_path.removesuffix('.git').rstrip('/')).name.lower()
            rows = []
            for path in paths:
                canonical = git(path, 'rev-parse', '--show-toplevel')
                if canonical != path or os.path.realpath(path) != path: raise SystemExit(42)
                safe_metadata(path, True)
                dot_git = pathlib.Path(path) / '.git'
                layout = 'checkout' if dot_git.is_dir() and not dot_git.is_symlink() else 'worktree'
                if layout == 'worktree' and (not dot_git.is_file() or dot_git.is_symlink()): raise SystemExit(42)
                safe_metadata(dot_git, layout == 'checkout')
                git_dir = os.path.realpath(git(path, 'rev-parse', '--absolute-git-dir'))
                safe_metadata(git_dir, True)
                safe_metadata(common, True)
                try: branch = git(path, 'symbolic-ref', '--short', 'HEAD')
                except subprocess.CalledProcessError: branch = None
                rows.append({
                    'path': path,
                    'layout': layout,
                    'repository_url': origin,
                    'branch': branch,
                    'detached': branch is None,
                    'commit': git(path, 'rev-parse', '--verify', 'HEAD^{commit}'),
                    'default_branch': default_branch,
                    'inferred_slug': slug,
                    'inferred_root': 'public' if (pathlib.Path(path, 'composer.json').is_file() and pathlib.Path(path, 'artisan').is_file() and pathlib.Path(path, 'public').is_dir()) else None,
                    'common_repository_path': common,
                    'worktree_paths': worktrees,
                    'source_digest': digest(path),
                })
            print(json.dumps(rows, separators=(',', ':')))
            PYTHON;
    }

    private static function relocationScript(): string
    {
        return <<<'PYTHON'
            import ctypes, errno, fcntl, hashlib, json, os, pathlib, shutil, stat, subprocess, sys, uuid
            operation = sys.argv[1]
            members = json.loads(sys.argv[2])
            git_env = dict(os.environ, GIT_OPTIONAL_LOCKS='0')

            def git(path, *args): return subprocess.check_output(['git', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env).decode().strip()
            def digest(path):
                root = pathlib.Path(path); h = hashlib.sha256()
                for args in [('rev-parse','HEAD'),('symbolic-ref','-q','HEAD'),('status','--porcelain=v2','--untracked-files=all'),('config','--local','--null','--list'),('show-ref','--head')]:
                    try: value = subprocess.check_output(['git','-C',path,*args], stderr=subprocess.DEVNULL, env=git_env)
                    except subprocess.CalledProcessError as error: value = error.output
                    h.update(b'git\0'+b'\0'.join(a.encode() for a in args)+b'\0'+value)
                for current, dirs, files in os.walk(root, topdown=True, followlinks=False):
                    if pathlib.Path(current) == root and '.git' in dirs: dirs.remove('.git')
                    dirs.sort()
                    for name in sorted(dirs+files):
                        entry=pathlib.Path(current)/name; relative=entry.relative_to(root).as_posix()
                        if relative == '.git': continue
                        info=entry.lstat(); h.update(relative.encode()+b'\0'+str(stat.S_IMODE(info.st_mode)).encode()+b'\0')
                        if entry.is_symlink(): h.update(b'L'+os.readlink(entry).encode())
                        elif entry.is_file():
                            h.update(b'F')
                            with entry.open('rb') as stream:
                                for block in iter(lambda: stream.read(1024*1024), b''): h.update(block)
                        else: h.update(b'D')
                return h.hexdigest()
            def verify(path, member):
                if not os.path.isdir(path) or os.path.realpath(path) != path: raise SystemExit(42)
                if git(path,'rev-parse','--verify','HEAD^{commit}') != member['commit']: raise SystemExit(42)
                try: branch=git(path,'symbolic-ref','--short','HEAD')
                except subprocess.CalledProcessError: branch=None
                if branch != member['branch'] or (branch is None) != member['detached']: raise SystemExit(42)
                if digest(path) != member['digest']: raise SystemExit(42)
            def require(condition):
                if not condition: raise SystemExit(42)
            def identity(info):
                return None if info is None else [info.st_dev, info.st_ino]
            def metadata(parent, name):
                try: return os.stat(name, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError: return None
            def open_directory(path, create=False):
                require(path.startswith('/') and os.path.normpath(path) == path)
                descriptor=os.open('/', os.O_RDONLY | os.O_DIRECTORY)
                chain=[identity(os.fstat(descriptor))]
                if path == '/': return descriptor,chain
                try:
                    for part in path.split('/')[1:]:
                        require(part not in ('', '.', '..'))
                        if create:
                            try: os.mkdir(part, 0o755, dir_fd=descriptor)
                            except FileExistsError: pass
                        following=os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
                        os.close(descriptor); descriptor=following
                        chain.append(identity(os.fstat(descriptor)))
                    return descriptor,chain
                except BaseException:
                    os.close(descriptor)
                    raise
            def rename_exclusive(source_parent, source, destination_parent, destination):
                library=ctypes.CDLL(None, use_errno=True)
                rename=library.renameat2
                rename.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
                rename.restype=ctypes.c_int
                if rename(source_parent,os.fsencode(source),destination_parent,os.fsencode(destination),1) != 0:
                    raise OSError(ctypes.get_errno(), 'Registration claim was refused.')
            def remove_tree(descriptor):
                for name in sorted(os.listdir(descriptor)):
                    before=metadata(descriptor,name)
                    require(before is not None)
                    if stat.S_ISDIR(before.st_mode):
                        child=os.open(name,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=descriptor)
                        try:
                            require(identity(os.fstat(child)) == identity(before))
                            remove_tree(child)
                            require(identity(metadata(descriptor,name)) == identity(before))
                            os.rmdir(name,dir_fd=descriptor)
                        finally: os.close(child)
                    else:
                        require(identity(metadata(descriptor,name)) == identity(before))
                        os.unlink(name,dir_fd=descriptor)
                os.fsync(descriptor)
            class Stage:
                def __init__(self, member):
                    self.member=member
                    require(isinstance(member['request'],str) and str(uuid.UUID(member['request'])) == member['request'])
                    self.parent_path=os.path.dirname(member['destination'])
                    self.source_parent_path=os.path.dirname(member['source'])
                    self.source_parent,self.source_parents=open_directory(self.source_parent_path)
                    self.source_name=os.path.basename(member['source'])
                    require(self.source_name not in ('','.','..'))
                    intent=member['receipt']
                    require(isinstance(intent,dict) and isinstance(intent.get('attempt'),str) and str(uuid.UUID(intent['attempt'])) == intent['attempt'])
                    initializing=operation == 'initialize' and intent['scope'] is None and intent['parents'] is None and intent['source_parents'] is None and intent['journal'] is None
                    self.parent,self.parents=open_directory(self.parent_path,create=initializing)
                    self.destination=os.path.basename(member['destination'])
                    require(metadata(self.parent,self.destination+'.orbit-stage-'+str(member['id'])) is None)
                    token=hashlib.sha256((member['request']+'\0'+member['source']+'\0'+member['destination']+'\0'+intent['attempt']).encode()).hexdigest()[:32]
                    self.name='.orbit-registration-'+str(member['id'])+'-'+token
                    self.original_claim='.orbit-original-'+str(member['id'])+'-'+intent['attempt']
                    self.path=self.parent_path+'/'+self.name
                    created=False
                    if initializing:
                        try:
                            os.mkdir(self.name,0o700,dir_fd=self.parent)
                            created=True
                        except FileExistsError: pass
                    observed=metadata(self.parent,self.name)
                    self.directory=os.open(self.name,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=self.parent)
                    scope_info=os.fstat(self.directory)
                    require(identity(scope_info) == identity(observed))
                    require(scope_info.st_uid == os.geteuid() and stat.S_IMODE(scope_info.st_mode) == 0o700)
                    self.scope=identity(scope_info)
                    fcntl.flock(self.directory,fcntl.LOCK_EX)
                    if created: require(not os.listdir(self.directory))
                    self.receipt=os.open('state.journal',os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | (os.O_CREAT | os.O_EXCL if created else 0),0o600,dir_fd=self.directory)
                    info=os.fstat(self.receipt)
                    require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1)
                    self.journal=identity(info)
                    binding={'version':1,'request':member['request'],'id':member['id'],'source':member['source'],'destination':member['destination'],'attempt':intent['attempt'],'parents':self.parents,'source_parents':self.source_parents,'scope':self.scope,'journal':self.journal}
                    require(intent == ({**binding,'parents':None,'source_parents':None,'scope':None,'journal':None} if initializing else binding))
                    self.binding=binding
                    if created:
                        os.ftruncate(self.receipt,32768)
                        self.revision=0
                        self.state={**binding,'phase':'new','stage':None,'original':None,'placed':None,'original_phase':'unobserved'}
                        self.save()
                    else:
                        require(info.st_size == 32768)
                        records=[record for slot in (0,1) if (record := self.read_record(slot)) is not None]
                        require(records)
                        if len(records) == 2: require(abs(records[0]['revision']-records[1]['revision']) == 1)
                        latest=max(records,key=lambda record:record['revision'])
                        self.revision=latest['revision']; self.state=latest['state']
                        require(set(self.state) == set(binding) | {'phase','stage','original','placed','original_phase'})
                        require(all(self.state[key] == value for key,value in binding.items()))
                        require(self.state['phase'] in ('new','moving','copying','claiming','claimed','cleaned','placing','placed'))
                        require(self.state['original_phase'] in ('unobserved','unknown','same','present','moved','claiming','claimed','deleted'))
                        for key in ('stage','original','placed'):
                            value=self.state[key]
                            require(value is None or isinstance(value,list) and len(value) == 2 and all(type(part) is int and part >= 0 for part in value))
                        if initializing: require(self.state['phase'] == 'new')
                    self.check()
                    require(set(os.listdir(self.directory)).issubset({'state.journal','stage','claimed'}))
                    stage=metadata(self.directory,'stage'); claimed=metadata(self.directory,'claimed')
                    require(stage is None or claimed is None)
                    for info in (stage,claimed):
                        if info is not None:
                            require(stat.S_ISDIR(info.st_mode) and identity(info) == self.state['stage'])
                    if claimed is not None: require(self.state['phase'] in ('claiming','claimed'))
                    if stage is not None: require(self.state['phase'] in ('copying','claiming','placing'))
                def check(self):
                    current,parents=open_directory(self.parent_path)
                    try:
                        require(parents == self.parents and identity(os.fstat(current)) == identity(os.fstat(self.parent)))
                        require(identity(metadata(current,self.name)) == self.scope)
                        require(metadata(current,self.destination+'.orbit-stage-'+str(self.member['id'])) is None)
                        require(identity(metadata(self.directory,'state.journal')) == self.journal)
                    finally: os.close(current)
                    current,parents=open_directory(self.source_parent_path)
                    try:
                        require(parents == self.source_parents and identity(os.fstat(current)) == identity(os.fstat(self.source_parent)))
                    finally: os.close(current)
                def read_record(self,slot):
                    frame=os.pread(self.receipt,16384,slot*16384)
                    length=int.from_bytes(frame[:4],'big')
                    if len(frame) != 16384 or not 0 < length <= 16348: return None
                    data=frame[36:36+length]
                    if hashlib.sha256(data).digest() != frame[4:36]: return None
                    try: record=json.loads(data)
                    except (ValueError,UnicodeError): return None
                    if not isinstance(record,dict) or set(record) != {'revision','state'}: return None
                    if type(record['revision']) is not int or not 0 < record['revision'] < 2**63: return None
                    if not isinstance(record['state'],dict): return None
                    return record
                def save(self):
                    self.check()
                    revision=self.revision+1
                    require(revision < 2**63)
                    data=json.dumps({'revision':revision,'state':self.state},separators=(',',':')).encode()
                    require(len(data) <= 16348)
                    frame=(len(data).to_bytes(4,'big')+hashlib.sha256(data).digest()+data).ljust(16384,b'\0')
                    written=0
                    while written < len(frame):
                        count=os.pwrite(self.receipt,frame[written:],(revision%2)*16384+written)
                        require(count > 0); written+=count
                    os.fsync(self.receipt)
                    self.check(); os.fsync(self.directory)
                    self.revision=revision
                def open_stage(self,name='stage'):
                    self.check()
                    descriptor=os.open(name,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=self.directory)
                    require(identity(os.fstat(descriptor)) == self.state['stage'])
                    return descriptor
                def discard(self):
                    self.check()
                    stage=metadata(self.directory,'stage'); claimed=metadata(self.directory,'claimed')
                    require(stage is None or claimed is None)
                    if stage is not None:
                        descriptor=self.open_stage(); os.close(descriptor)
                        self.state['phase']='claiming'; self.save()
                        rename_exclusive(self.directory,'stage',self.directory,'claimed')
                        if identity(metadata(self.directory,'claimed')) != self.state['stage']:
                            try: rename_exclusive(self.directory,'claimed',self.directory,'stage')
                            except OSError: pass
                            raise SystemExit(42)
                    elif claimed is None:
                        require(self.state['phase'] == 'claimed')
                        self.state['phase']='cleaned'; self.save()
                        return
                    descriptor=self.open_stage('claimed')
                    try:
                        self.state['phase']='claimed'; self.save()
                        remove_tree(descriptor)
                        self.check()
                        require(identity(metadata(self.directory,'claimed')) == self.state['stage'])
                        os.rmdir('claimed',dir_fd=self.directory); os.fsync(self.directory)
                    finally: os.close(descriptor)
                    self.state['phase']='cleaned'; self.save()
                def copy(self,source):
                    require(metadata(self.directory,'stage') is None and metadata(self.directory,'claimed') is None)
                    os.mkdir('stage',0o700,dir_fd=self.directory)
                    observed=metadata(self.directory,'stage')
                    descriptor=os.open('stage',os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=self.directory)
                    try:
                        require(identity(os.fstat(descriptor)) == identity(observed))
                        self.state.update(phase='copying',stage=identity(os.fstat(descriptor)))
                        self.save()
                        shutil.copytree(source,'/proc/self/fd/'+str(descriptor),symlinks=True,copy_function=shutil.copy2,dirs_exist_ok=True)
                        os.fsync(descriptor)
                    finally: os.close(descriptor)
                def place(self):
                    descriptor=self.open_stage(); os.close(descriptor)
                    self.state['phase']='placing'; self.save()
                    rename_exclusive(self.directory,'stage',self.parent,self.destination)
                    os.fsync(self.parent); os.fsync(self.directory)
                    if identity(metadata(self.parent,self.destination)) != self.state['stage']:
                        try: rename_exclusive(self.parent,self.destination,self.directory,'stage')
                        except OSError: pass
                        raise SystemExit(42)
                    self.state.update(phase='placed',placed=self.state['stage']); self.save()
                def result(self):
                    original=self.state['original'] or [None,None]
                    return {'id':self.member['id'],'source_device':original[0],'source_inode':original[1]}
            def source_identity(path):
                if not os.path.isdir(path) or os.path.islink(path) or os.path.realpath(path) != path: raise SystemExit(42)
                info=os.lstat(path)
                return info.st_dev,info.st_ino
            def prepare(member):
                source=member['source']; destination=member['destination']; scope=scopes[member['id']]
                scope.check()
                if source == destination:
                    verify(destination, member)
                    scope.state.update(phase='placed',placed=list(source_identity(destination)),original_phase='same'); scope.save()
                    return {'id':member['id'],'source_device':None,'source_inode':None}
                if os.path.lexists(destination):
                    verify(destination, member)
                    destination_identity=list(source_identity(destination))
                    if scope.state['phase'] == 'new':
                        if os.path.lexists(source):
                            verify(source,member)
                            scope.state.update(original=list(source_identity(source)),original_phase='present')
                        else: scope.state['original_phase']='unknown'
                    else:
                        expected=scope.state['placed'] or (scope.state['stage'] if scope.state['phase'] == 'placing' else scope.state['original'])
                        require(scope.state['phase'] in ('moving','placing','placed') and destination_identity == expected)
                        if scope.state['phase'] == 'moving': scope.state['original_phase']='moved'
                    if os.path.lexists(source):
                        verify(source, member)
                        require(list(source_identity(source)) == scope.state['original'])
                    require(metadata(scope.directory,'stage') is None and metadata(scope.directory,'claimed') is None)
                    scope.state.update(phase='placed',placed=destination_identity); scope.save()
                    return scope.result()
                require(scope.state['phase'] != 'placed')
                verify(source,member)
                original=list(source_identity(source))
                if scope.state['original'] is None:
                    scope.state.update(original=original,original_phase='present'); scope.save()
                require(scope.state['original'] == original)
                if scope.state['phase'] in ('claiming','claimed'):
                    scope.discard()
                if metadata(scope.directory,'stage') is not None:
                    try: verify(scope.path+'/stage',member)
                    except (SystemExit,subprocess.CalledProcessError,OSError): scope.discard()
                    else:
                        scope.place(); verify(destination,member)
                        return scope.result()
                require(scope.state['phase'] in ('new','moving','cleaned'))
                source_parent,unused=open_directory(os.path.dirname(source))
                try:
                    scope.state['phase']='moving'; scope.save()
                    try:
                        rename_exclusive(source_parent,os.path.basename(source),scope.parent,scope.destination)
                        os.fsync(source_parent); os.fsync(scope.parent)
                        if identity(metadata(scope.parent,scope.destination)) != original:
                            try: rename_exclusive(scope.parent,scope.destination,source_parent,os.path.basename(source))
                            except OSError: pass
                            raise SystemExit(42)
                        scope.state.update(phase='placed',placed=original,original_phase='moved'); scope.save()
                    except OSError as error:
                        if error.errno != errno.EXDEV: raise
                        scope.copy(source)
                        verify(scope.path+'/stage',member)
                        scope.place()
                finally: os.close(source_parent)
                verify(destination, member)
                return scope.result()
            def repair():
                mapping={m['source']:m['destination'] for m in members}
                checkouts=[m for m in members if m['layout']=='checkout']
                if checkouts:
                    checkout=checkouts[0]
                    retained=[mapping.get(path,path) for path in checkout['worktrees'] if path != checkout['source']]
                    subprocess.check_call(['git','-C',checkout['destination'],'worktree','repair',*retained], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                else:
                    member=members[0]
                    subprocess.check_call(['git','--git-dir',member['common'],'worktree','repair',member['destination']], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            def cleanup(member):
                source=member['source']; destination=member['destination']; scope=scopes[member['id']]
                scope.check()
                verify(destination, member)
                require(scope.state['phase'] == 'placed' and list(source_identity(destination)) == scope.state['placed'])
                admit_original(member)
            def admit_original(member):
                scope=scopes[member['id']]
                scope.check()
                phase=scope.state['original_phase']
                source=metadata(scope.source_parent,scope.source_name)
                claimed=metadata(scope.source_parent,scope.original_claim)
                if phase == 'same':
                    require(member['source'] == member['destination'] and claimed is None)
                    return
                require(member['source'] != member['destination'])
                original=[member['source_device'],member['source_inode']]
                require(scope.state['original'] is not None and original == scope.state['original'])
                if phase in ('moved','deleted'):
                    require(claimed is None)
                    return
                require(phase in ('present','claiming','claimed'))
                if claimed is not None:
                    require(phase in ('claiming','claimed') and stat.S_ISDIR(claimed.st_mode) and identity(claimed) == original)
                elif phase == 'claimed':
                    require(source is None or identity(source) != original)
                elif phase != 'claimed':
                    require(source is not None and stat.S_ISDIR(source.st_mode) and identity(source) == original)
            def remove_original(member):
                scope=scopes[member['id']]
                cleanup(member)
                if scope.state['original_phase'] in ('same','moved','deleted'): return
                if metadata(scope.source_parent,scope.original_claim) is None:
                    if scope.state['original_phase'] == 'claimed':
                        scope.state['original_phase']='deleted'; scope.save()
                        return
                    descriptor=os.open(scope.source_name,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=scope.source_parent)
                    try: require(identity(os.fstat(descriptor)) == scope.state['original'])
                    finally: os.close(descriptor)
                    scope.state['original_phase']='claiming'; scope.save()
                    rename_exclusive(scope.source_parent,scope.source_name,scope.source_parent,scope.original_claim)
                    os.fsync(scope.source_parent)
                    if identity(metadata(scope.source_parent,scope.original_claim)) != scope.state['original']:
                        try: rename_exclusive(scope.source_parent,scope.original_claim,scope.source_parent,scope.source_name)
                        except OSError: pass
                        raise SystemExit(42)
                descriptor=os.open(scope.original_claim,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=scope.source_parent)
                try:
                    require(identity(os.fstat(descriptor)) == scope.state['original'])
                    scope.state['original_phase']='claimed'; scope.save()
                    cleanup(member)
                    remove_tree(descriptor)
                    scope.check()
                    require(identity(metadata(scope.source_parent,scope.original_claim)) == scope.state['original'])
                    os.rmdir(scope.original_claim,dir_fd=scope.source_parent); os.fsync(scope.source_parent)
                finally: os.close(descriptor)
                scope.state['original_phase']='deleted'; scope.save()
            require(operation in ('initialize','prepare','cleanup'))
            require(len({member['id'] for member in members}) == len(members))
            scopes={member['id']:Stage(member) for member in sorted(members,key=lambda member:member['id'])}
            ordered = sorted(members, key=lambda m: 1 if m['layout'] == 'checkout' else 0)
            if operation == 'initialize':
                print(json.dumps([scopes[member['id']].binding for member in members],separators=(',',':')))
            elif operation == 'prepare':
                identities=[prepare(member) for member in ordered]
                repair()
                for member in members: verify(member['destination'], member)
                print(json.dumps(identities,separators=(',',':')))
            elif operation == 'cleanup':
                for member in members: cleanup(member)
                for member in ordered: remove_original(member)
                for member in members: verify(member['destination'], member)
            else: raise SystemExit(42)
            PYTHON;
    }

    private static function laravelReceiptScript(): string
    {
        return <<<'PYTHON'
            import ctypes, fcntl, hashlib, json, os, pathlib, shutil, stat, sys, uuid
            operation=sys.argv[1]; member=json.loads(sys.argv[2]); root=member['checkout']
            os.umask(0o077)
            def require(value):
                if not value: raise SystemExit(42)
            def identity(info):
                return None if info is None else [info.st_dev,info.st_ino]
            def valid_identity(value):
                return isinstance(value,list) and len(value) == 2 and all(type(part) is int and part >= 0 for part in value)
            def metadata(parent,name):
                try: return os.stat(name,dir_fd=parent,follow_symlinks=False)
                except FileNotFoundError: return None
            def open_directory(path):
                require(path.startswith('/') and os.path.normpath(path) == path)
                descriptor=os.open('/',os.O_RDONLY | os.O_DIRECTORY); chain=[identity(os.fstat(descriptor))]
                if path == '/': return descriptor,chain
                try:
                    for part in path.split('/')[1:]:
                        require(part not in ('','.','..'))
                        observed=metadata(descriptor,part)
                        child=os.open(part,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=descriptor)
                        require(identity(os.fstat(child)) == identity(observed))
                        os.close(descriptor); descriptor=child; chain.append(identity(os.fstat(descriptor)))
                    return descriptor,chain
                except BaseException:
                    os.close(descriptor); raise
            def open_child(parent,name,expected=None):
                observed=metadata(parent,name)
                require(observed is not None and stat.S_ISDIR(observed.st_mode))
                if expected is not None: require(identity(observed) == expected)
                descriptor=os.open(name,os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,dir_fd=parent)
                require(identity(os.fstat(descriptor)) == identity(observed))
                return descriptor
            def private(info,mode):
                require(info is not None and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == mode)
                require(stat.S_ISDIR(info.st_mode) if mode == 0o700 else stat.S_ISREG(info.st_mode) and info.st_nlink == 1)
            def rename_exclusive(parent,source,destination):
                libc=ctypes.CDLL(None,use_errno=True)
                if libc.renameat2(parent,os.fsencode(source),parent,os.fsencode(destination),1) != 0:
                    error=ctypes.get_errno(); raise OSError(error,os.strerror(error))
            def digest(descriptor):
                result=hashlib.sha256(); os.lseek(descriptor,0,os.SEEK_SET)
                while True:
                    chunk=os.read(descriptor,1048576)
                    if not chunk: return result.hexdigest()
                    result.update(chunk)
            require(root.startswith('/') and os.path.normpath(root) == root and root != '/')
            parent,parents=open_directory(os.path.dirname(root))
            legacy='.orbit-registration-url-'+str(member['id'])
            require(metadata(parent,legacy) is None and metadata(parent,legacy+'.preparing') is None)
            if member['receipt'] is None:
                require(operation == 'discard'); print('null'); raise SystemExit(0)
            class LaravelReceipt:
                def __init__(self):
                    receipt=member['receipt']; intent=receipt['binding']
                    require(set(receipt) == {'binding','outcome'})
                    require(isinstance(member['request'],str) and str(uuid.UUID(member['request'])) == member['request'])
                    require(str(uuid.UUID(intent['attempt'])) == intent['attempt'])
                    self.name=legacy+'-'+intent['attempt']; self.parent=parent
                    self.root=open_child(parent,os.path.basename(root))
                    self.directories=[self.root]
                    for part in ('bootstrap','cache'):
                        previous=self.directories[-1]
                        self.directories.append(None if previous is None or metadata(previous,part) is None else open_child(previous,part))
                    directory_ids=[None if fd is None else identity(os.fstat(fd)) for fd in self.directories]
                    initializing=operation == 'initialize' and receipt['outcome'] == 'pending'
                    created=False
                    if initializing:
                        try: os.mkdir(self.name,0o700,dir_fd=parent); os.fsync(parent); created=True
                        except FileExistsError: pass
                    self.directory=open_child(parent,self.name); private(os.fstat(self.directory),0o700)
                    fcntl.flock(self.directory,fcntl.LOCK_EX)
                    flags=os.O_RDWR | os.O_NOFOLLOW
                    if created: flags |= os.O_CREAT | os.O_EXCL
                    self.journal=os.open('state.journal',flags,0o600,dir_fd=self.directory)
                    private(os.fstat(self.journal),0o600)
                    if created:
                        os.mkdir('backup',0o700,dir_fd=self.directory); os.fsync(self.directory)
                    backup_info=metadata(self.directory,'backup') or metadata(self.directory,'deleting')
                    backup_identity=identity(backup_info) if initializing else intent['backup']
                    self.binding={'version':1,'request':member['request'],'id':member['id'],'node':member['node'],'checkout':root,'attempt':intent['attempt'],'parents':parents,'directories':directory_ids,'scope':identity(os.fstat(self.directory)),'journal':identity(os.fstat(self.journal)),'backup':backup_identity}
                    pending={**self.binding,**{key:None for key in ('parents','directories','scope','journal','backup')}}
                    require(intent == (pending if initializing else self.binding))
                    if created:
                        self.revision=0
                        self.state={'binding':self.binding,'phase':'new','action':None,'files':None,'times':[None if fd is None else [os.fstat(fd).st_atime_ns,os.fstat(fd).st_mtime_ns] for fd in self.directories]}
                        os.ftruncate(self.journal,32768); self.save()
                    else:
                        require(os.fstat(self.journal).st_size == 32768)
                        records=[record for record in (self.read_record(0),self.read_record(1)) if record is not None]
                        require(records)
                        if len(records) == 2: require(abs(records[0]['revision']-records[1]['revision']) == 1)
                        latest=max(records,key=lambda record:record['revision']); self.revision=latest['revision']; self.state=latest['state']
                        require(set(self.state) == {'binding','phase','action','files','times'} and self.state['binding'] == self.binding)
                        require(self.state['phase'] in ('new','preparing','ready','restoring','restored_files','claiming','claimed','restored','discarded'))
                        require(self.state['action'] in (None,'restore','discard'))
                        require(isinstance(self.state['times'],list) and len(self.state['times']) == 3)
                        for index,times in enumerate(self.state['times']):
                            require(times is None or isinstance(times,list) and len(times) == 2 and all(type(value) is int for value in times))
                            require((times is None) == (self.binding['directories'][index] is None))
                        if self.state['phase'] == 'new': require(self.state['files'] is None)
                        else:
                            require(isinstance(self.state['files'],list) and len(self.state['files']) == 2)
                            for value in self.state['files']:
                                if value is None: continue
                                require(isinstance(value,dict) and set(value) == {'source','backup','ready'} and type(value['ready']) is bool)
                                require(value['backup'] is None or valid_identity(value['backup']))
                                source=value['source']; require(isinstance(source,dict) and set(source) == {'identity','mode','times','size','digest'})
                                require(valid_identity(source['identity']) and type(source['mode']) is int and 0 <= source['mode'] <= 0o7777)
                                require(type(source['size']) is int and source['size'] >= 0)
                                require(isinstance(source['times'],list) and len(source['times']) == 2 and all(type(part) is int for part in source['times']))
                                require(isinstance(source['digest'],str) and len(source['digest']) == 64 and all(part in '0123456789abcdef' for part in source['digest']))
                                if self.state['phase'] != 'preparing': require(value['ready'] and value['backup'] is not None)
                        if self.state['phase'] in ('new','preparing','ready'): require(self.state['action'] is None)
                        elif self.state['phase'] in ('restoring','restored_files','restored'): require(self.state['action'] == 'restore')
                        elif self.state['phase'] == 'discarded': require(self.state['action'] == 'discard')
                        else: require(self.state['action'] in ('restore','discard'))
                        if initializing: require(self.state['phase'] == 'new')
                    self.check()
                def check(self):
                    current,chain=open_directory(os.path.dirname(root))
                    try:
                        require(chain == self.binding['parents'])
                        require(identity(metadata(current,self.name)) == self.binding['scope'])
                        require(identity(metadata(current,os.path.basename(root))) == self.binding['directories'][0])
                        require(metadata(current,legacy) is None and metadata(current,legacy+'.preparing') is None)
                    finally: os.close(current)
                    private(os.fstat(self.directory),0o700)
                    require(identity(metadata(self.directory,'state.journal')) == self.binding['journal'])
                    private(metadata(self.directory,'state.journal'),0o600)
                    require(set(os.listdir(self.directory)).issubset({'state.journal','backup','deleting'}))
                    backup=metadata(self.directory,'backup'); deleting=metadata(self.directory,'deleting')
                    require(backup is None or deleting is None)
                    for info in (backup,deleting):
                        if info is not None:
                            private(info,0o700); require(identity(info) == self.binding['backup'])
                    if self.state['phase'] in ('restored','discarded'): require(backup is None and deleting is None)
                    elif self.state['phase'] == 'claimed': require(backup is None)
                    elif self.state['phase'] == 'claiming': require(backup is not None or deleting is not None)
                    else: require(backup is not None and deleting is None)
                def read_record(self,slot):
                    frame=os.pread(self.journal,16384,slot*16384); length=int.from_bytes(frame[:4],'big')
                    if len(frame) != 16384 or not 0 < length <= 16348: return None
                    data=frame[36:36+length]
                    if hashlib.sha256(data).digest() != frame[4:36]: return None
                    try: record=json.loads(data)
                    except (ValueError,UnicodeError): return None
                    if not isinstance(record,dict) or set(record) != {'revision','state'}: return None
                    if type(record['revision']) is not int or not 0 < record['revision'] < 2**63 or not isinstance(record['state'],dict): return None
                    return record
                def save(self):
                    self.check(); revision=self.revision+1; require(revision < 2**63)
                    data=json.dumps({'revision':revision,'state':self.state},separators=(',',':')).encode(); require(len(data) <= 16348)
                    frame=(len(data).to_bytes(4,'big')+hashlib.sha256(data).digest()+data).ljust(16384,b'\0'); written=0
                    while written < len(frame):
                        count=os.pwrite(self.journal,frame[written:],(revision%2)*16384+written); require(count > 0); written+=count
                    os.fsync(self.journal); self.check(); os.fsync(self.directory); self.revision=revision
                def source_file(self,index):
                    parent=self.directories[0 if index == 0 else 2]; name='.env' if index == 0 else 'config.php'
                    info=None if parent is None else metadata(parent,name)
                    if info is None: return None,None
                    require(stat.S_ISREG(info.st_mode))
                    descriptor=os.open(name,os.O_RDONLY | os.O_NOFOLLOW,dir_fd=parent)
                    require(identity(os.fstat(descriptor)) == identity(info))
                    result={'identity':identity(info),'mode':stat.S_IMODE(info.st_mode),'times':[info.st_atime_ns,info.st_mtime_ns],'size':info.st_size,'digest':digest(descriptor)}
                    after=os.fstat(descriptor)
                    require((after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns) == (info.st_dev,info.st_ino,info.st_size,info.st_mtime_ns,info.st_ctime_ns))
                    return descriptor,result
                def backup(self,partial=False):
                    self.check(); name='deleting' if metadata(self.directory,'deleting') is not None else 'backup'
                    if metadata(self.directory,name) is None:
                        require(partial and self.state['phase'] == 'claimed'); return None
                    descriptor=open_child(self.directory,name,self.binding['backup'])
                    files=self.state['files']; require(isinstance(files,list) and len(files) == 2)
                    expected={str(index) for index,value in enumerate(files) if value is not None and value['backup'] is not None}
                    actual=set(os.listdir(descriptor)); require(actual.issubset(expected) if partial else actual == expected)
                    for index,value in enumerate(files):
                        if value is None: continue
                        require(set(value) == {'source','backup','ready'} and type(value['ready']) is bool)
                        info=metadata(descriptor,str(index))
                        if info is None:
                            require(partial or value['backup'] is None); continue
                        private(info,0o600); require(identity(info) == value['backup'])
                        file=os.open(str(index),os.O_RDONLY | os.O_NOFOLLOW,dir_fd=descriptor)
                        try:
                            require(identity(os.fstat(file)) == value['backup'])
                            if value['ready']: require(os.fstat(file).st_size == value['source']['size'] and digest(file) == value['source']['digest'])
                        finally: os.close(file)
                    return descriptor
                def prepare(self):
                    if self.state['action'] == 'restore':
                        self.restore(); return
                    require(self.state['action'] is None and self.state['phase'] in ('new','preparing','ready'))
                    if self.state['phase'] == 'new':
                        files=[]
                        for index in range(2):
                            descriptor,source=self.source_file(index)
                            if descriptor is not None: os.close(descriptor)
                            files.append(None if source is None else {'source':source,'backup':None,'ready':False})
                        self.state.update(phase='preparing',files=files); self.save()
                    backup=self.backup()
                    try:
                        for index,value in enumerate(self.state['files']):
                            if value is None or value['ready']: continue
                            source,current=self.source_file(index)
                            require(current is not None and all(current[key] == value['source'][key] for key in ('identity','mode','size','digest')) and current['times'][1] == value['source']['times'][1])
                            try:
                                if value['backup'] is None:
                                    output=os.open(str(index),os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,0o600,dir_fd=backup)
                                    value['backup']=identity(os.fstat(output)); os.fsync(backup); self.save()
                                else: output=os.open(str(index),os.O_WRONLY | os.O_NOFOLLOW,dir_fd=backup)
                                try:
                                    require(identity(os.fstat(output)) == value['backup']); os.ftruncate(output,0); os.lseek(source,0,os.SEEK_SET)
                                    while True:
                                        chunk=os.read(source,1048576)
                                        if not chunk: break
                                        written=0
                                        while written < len(chunk):
                                            count=os.write(output,chunk[written:]); require(count > 0); written+=count
                                    os.fsync(output)
                                finally: os.close(output)
                                value['ready']=True; self.save()
                            finally: os.close(source)
                        self.state['phase']='ready'; self.save()
                    finally: os.close(backup)
                    checked=self.backup(); os.close(checked)
                def restore(self):
                    if self.state['phase'] == 'restored': return
                    require(self.state['action'] in (None,'restore'))
                    require(self.state['phase'] in ('ready','restoring','restored_files','claiming','claimed'))
                    if self.state['phase'] in ('ready','restoring'):
                        backup=self.backup()
                        try:
                            self.state.update(phase='restoring',action='restore'); self.save()
                            paths=[pathlib.Path(root)/'.env',pathlib.Path(root)/'bootstrap'/'cache'/'config.php']
                            for index,path in enumerate(paths):
                                value=self.state['files'][index]
                                if value is not None:
                                    path.parent.mkdir(parents=True,exist_ok=True)
                                    shutil.copyfile('/proc/self/fd/'+str(backup)+'/'+str(index),path)
                                    os.chmod(path,value['source']['mode']); os.utime(path,ns=tuple(value['source']['times']))
                                elif path.exists() and not path.is_symlink(): path.unlink()
                            directories=[pathlib.Path(root),pathlib.Path(root)/'bootstrap',pathlib.Path(root)/'bootstrap'/'cache']
                            for path,times in reversed(list(zip(directories,self.state['times']))):
                                if times is not None and path.is_dir() and not path.is_symlink(): os.utime(path,ns=tuple(times))
                            self.state['phase']='restored_files'; self.save()
                        finally: os.close(backup)
                    self.cleanup('restore')
                def cleanup(self,action):
                    terminal='restored' if action == 'restore' else 'discarded'
                    if self.state['phase'] == terminal: return
                    require(self.state['action'] in (None,action))
                    require(self.state['phase'] in (('restored_files','claiming','claimed') if action == 'restore' else ('ready','claiming','claimed')))
                    backup=self.backup(partial=self.state['phase'] == 'claimed')
                    if backup is None:
                        self.state['phase']=terminal; self.save(); return
                    try:
                        self.state['action']=action
                        if self.state['phase'] != 'claimed':
                            self.state['phase']='claiming'; self.save()
                            if metadata(self.directory,'deleting') is None:
                                rename_exclusive(self.directory,'backup','deleting'); os.fsync(self.directory)
                                if identity(metadata(self.directory,'deleting')) != self.binding['backup']:
                                    try: rename_exclusive(self.directory,'deleting','backup')
                                    except OSError: pass
                                    raise SystemExit(42)
                            self.state['phase']='claimed'; self.save()
                        checked=self.backup(partial=True); os.close(checked)
                        for name in ('0','1'):
                            info=metadata(backup,name)
                            if info is not None:
                                require(identity(info) == self.state['files'][int(name)]['backup']); os.unlink(name,dir_fd=backup); os.fsync(backup)
                        self.check(); require(identity(metadata(self.directory,'deleting')) == self.binding['backup'])
                        os.rmdir('deleting',dir_fd=self.directory); os.fsync(self.directory)
                    finally: os.close(backup)
                    self.state['phase']=terminal; self.save()
            require(operation in ('initialize','prepare','restore','discard'))
            receipt=LaravelReceipt()
            if operation == 'prepare': receipt.prepare()
            elif operation == 'restore': receipt.restore()
            elif operation == 'discard': receipt.cleanup('discard')
            outcome='initialized' if operation == 'initialize' else receipt.state['phase']
            require(outcome in ('initialized','ready','restored','discarded'))
            print(json.dumps({'binding':receipt.binding,'outcome':outcome},separators=(',',':')))
            PYTHON;
    }
}
