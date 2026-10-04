<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tasks\TaskWorkerUser;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use JsonException;

final readonly class RemoteRegistrationSourceManager implements RegistrationSourceManager
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
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
                    '-Ic',
                    self::inspectionScript(),
                    $sourcePath,
                    $includeWorktrees ? '1' : '0',
                    $account->user,
                    $account->group,
                    TaskWorkerUser::name() ?? '',
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

    public function relocate(Instance $instance, RegistrationSourceFacts $facts): void
    {
        $this->relocateSet([['instance' => $instance, 'facts' => $facts]]);
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

        $node = $members[0]['instance']->node;
        $payload = [];
        $relocated = 0;
        $cleanupReady = 0;

        foreach ($members as $member) {
            $instance = $member['instance'];
            $facts = $member['facts'];

            if ($instance->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.source_conflict',
                    'Registration sources must share one Node.',
                    409,
                );
            }

            $payload[] = [
                'id' => $instance->id,
                'source' => $facts->path,
                'destination' => $instance->checkout_path,
                'application' => $instance->applicationDirectory(),
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

        if (array_all($members, static fn (array $member): bool => $member['facts']->path === $member['instance']->checkout_path)) {
            if ($cleanupReady === 0) {
                DB::transaction(static function () use ($members): void {
                    foreach ($members as $member) {
                        Instance::query()->whereKey($member['instance']->id)->update([
                            'registration_relocation_state' => 'relocating',
                            'registration_authoritative_path' => $member['facts']->path,
                        ]);
                    }
                });

                // An in-place source needs verification, not relocation or Git-link repair.
                $this->runRelocation($node, 'adopt', $payload);

                DB::transaction(static function () use ($members): void {
                    foreach ($members as $member) {
                        Instance::query()->whereKey($member['instance']->id)->update([
                            'registration_relocation_state' => 'destination_verified',
                            'registration_authoritative_path' => $member['instance']->checkout_path,
                            'registration_source_device' => null,
                            'registration_source_inode' => null,
                        ]);
                    }
                });
            }

            foreach ($members as $member) {
                $this->validateRetained($node, $member['facts'], $member['instance']->checkout_path);
            }

            // Persist adoption before changing environment permissions, so an interrupted
            // finalization never compares those changes with the pre-adoption digest.
            $this->runRelocation($node, 'adopt-finalize', $payload);

            DB::transaction(static function () use ($members): void {
                foreach ($members as $member) {
                    Instance::query()->whereKey($member['instance']->id)->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['instance']->checkout_path,
                        'registration_source_device' => null,
                        'registration_source_inode' => null,
                    ]);
                }
            });

            return;
        }

        if ($cleanupReady === 0) {
            DB::transaction(static function () use ($members): void {
                foreach ($members as $member) {
                    Instance::query()
                        ->whereKey($member['instance']->id)
                        ->update([
                            'registration_relocation_state' => 'relocating',
                            'registration_authoritative_path' => $member['facts']->path,
                            'registration_source_device' => null,
                            'registration_source_inode' => null,
                        ]);
                }
            });

            $result = $this->runRelocation($node, 'prepare', $payload);
            $identities = $this->preparationIdentities($result->stdout, $members);

            DB::transaction(static function () use ($members, $identities): void {
                foreach ($members as $member) {
                    $instance = $member['instance'];
                    $identity = $identities[$instance->id];
                    Instance::query()
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
                Instance::query()
                    ->whereKey($member['instance']->id)
                    ->update(['registration_relocation_state' => 'original_cleanup']);
            }
        });

        $this->runRelocation($node, 'cleanup', $payload);

        DB::transaction(static function () use ($members): void {
            foreach ($members as $member) {
                Instance::query()
                    ->whereKey($member['instance']->id)
                    ->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['instance']->checkout_path,
                    ]);
            }
        });
    }

    /**
     * @param  list<array{instance: Instance, facts: RegistrationSourceFacts}>  $members
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
            static fn (array $member): int => $member['instance']->id,
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
                '-Ic',
                self::relocationScript(),
                $operation,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                TaskWorkerUser::name() ?? '',
            ]),
            step: 'registration-source-relocate',
            errorCode: 'instance.registration_incomplete',
            commandTimeout: 900,
        );
    }

    public function restoreOriginal(Instance $instance, RegistrationSourceFacts $facts): void
    {
        $destination = $instance->checkout_path;
        $instance->checkout_path = $facts->path;
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
            commonRepositoryPath: $facts->layout === InstanceSourceLayout::Checkout
                ? $destination.'/.git'
                : $facts->commonRepositoryPath,
            worktreePaths: array_map(
                static fn (string $path): string => $path === $facts->path ? $destination : $path,
                $facts->worktreePaths,
            ),
            sourceDigest: $facts->sourceDigest,
        );

        $this->relocate($instance, $reverse);
    }

    public function prepareLaravelRollback(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'prepare');
    }

    public function restoreLaravelConfiguration(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'restore');
    }

    public function discardLaravelRollback(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'discard');
    }

    private function runLaravelReceipt(Instance $instance, string $operation): void
    {
        $instance->loadMissing('node');
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand([
                'python3',
                '-c',
                self::laravelReceiptScript(),
                $instance->checkout_path,
                (string) $instance->id,
                $operation,
                $instance->applicationDirectory(),
            ]),
            step: 'registration-laravel-rollback',
            errorCode: 'instance.laravel_rollback_failed',
            commandTimeout: 30,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function facts(array $row): RegistrationSourceFacts
    {
        $path = $this->requiredString($row, 'path');
        $layout = InstanceSourceLayout::tryFrom($this->requiredString($row, 'layout'));
        $repositoryUrl = GitRepositoryOrigin::validate($this->requiredString($row, 'repository_url'));
        $branch = is_string($row['branch'] ?? null) ? $row['branch'] : null;
        $defaultBranch = is_string($row['default_branch'] ?? null) ? $row['default_branch'] : null;
        $worktreePaths = $row['worktree_paths'] ?? null;

        if (
            ! $layout instanceof InstanceSourceLayout
            || $branch !== null
            && ! GitBranchName::isValid($branch)
            || $defaultBranch !== null
            && ! GitBranchName::isValid($defaultBranch)
            || ! is_bool($row['detached'] ?? null)
            || ! is_array($worktreePaths)
            || ! array_is_list($worktreePaths)
            || array_filter($worktreePaths, static fn (mixed $value): bool => ! is_string($value)) !== []
        ) {
            throw $this->invalidSource();
        }

        $validatedWorktreePaths = [];
        foreach ($worktreePaths as $worktreePath) {
            if (! is_string($worktreePath)) {
                throw $this->invalidSource();
            }
            $validatedWorktreePaths[] = $worktreePath;
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
            worktreePaths: $validatedWorktreePaths,
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

    private static function workerStatusScript(): string
    {
        return <<<'PYTHON'
            import contextlib, errno, os, pathlib, pwd, stat, struct, subprocess, sys

            worker = sys.argv[-1]

            class ReadGrant:
                def __init__(self):
                    self.stack = contextlib.ExitStack()
                    self.anchors = []

                def keep(self, fd):
                    self.stack.callback(os.close, fd)
                    return fd

                def pin(self, path, directory=True):
                    if not os.path.isabs(path) or os.path.normpath(path) != path: raise SystemExit(42)
                    parent = self.keep(os.open('/', os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC))
                    parts = pathlib.Path(path).parts[1:]
                    for index, name in enumerate(parts):
                        flags = os.O_PATH | os.O_NOFOLLOW | os.O_CLOEXEC
                        if directory or index != len(parts) - 1: flags |= os.O_DIRECTORY
                        fd = self.keep(os.open(name, flags, dir_fd=parent))
                        info = os.fstat(fd)
                        if stat.S_ISLNK(info.st_mode): raise SystemExit(42)
                        self.anchors.append((parent, name, fd, info.st_size, info.st_mtime_ns))
                        parent = fd
                    if os.fstat(parent).st_uid != os.geteuid(): raise SystemExit(42)
                    return parent

                def verify(self):
                    for parent, name, fd, size, mtime in self.anchors:
                        current = os.stat(name, dir_fd=parent, follow_symlinks=False)
                        pinned = os.fstat(fd)
                        if (current.st_dev, current.st_ino, stat.S_IFMT(current.st_mode)) != (pinned.st_dev, pinned.st_ino, stat.S_IFMT(pinned.st_mode)): raise SystemExit(42)
                        if stat.S_ISREG(pinned.st_mode) and (pinned.st_size, pinned.st_mtime_ns) != (size, mtime): raise SystemExit(42)

                def add_reads(self, fd, uid, groups):
                    self.verify()
                    info = os.fstat(fd)
                    if not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)): return
                    if stat.S_ISREG(info.st_mode) and info.st_nlink != 1: return
                    if info.st_uid == os.geteuid():
                        target = '/proc/self/fd/' + str(fd)
                        try: original = os.getxattr(target, 'system.posix_acl_access')
                        except OSError as error:
                            if error.errno != errno.ENODATA: raise
                            original = None
                        entries = list(struct.iter_unpack('<HHI', original[4:])) if original else [(1, (info.st_mode >> 6) & 7, 0xffffffff), (4, (info.st_mode >> 3) & 7, 0xffffffff), (32, info.st_mode & 7, 0xffffffff)]
                        mask = next((perm for tag, perm, identity in entries if tag == 16), (info.st_mode >> 3) & 7)
                        named = next((perm & mask for tag, perm, identity in entries if tag == 2 and identity == uid), None)
                        matching = [perm for tag, perm, identity in entries if tag == 4 and info.st_gid in groups or tag == 8 and identity in groups]
                        effective = named if named is not None else ((sum_bits(matching) & mask) if matching else next(perm for tag, perm, identity in entries if tag == 32))
                        needed = 4 | (1 if stat.S_ISDIR(info.st_mode) or info.st_mode & 0o111 else 0)
                        desired = effective | needed
                        # Freeze other masked entries before adding mask bits for the worker.
                        updated = [(tag, perm & mask if tag in (2, 4, 8) else perm, identity) for tag, perm, identity in entries if tag != 16 and not (tag == 2 and identity == uid)]
                        updated.extend([(2, desired, uid), (16, mask | desired, 0xffffffff)])
                        updated.sort(key=lambda entry: (entry[0], entry[2]))
                        value = struct.pack('<I', 2) + b''.join(struct.pack('<HHI', *entry) for entry in updated)
                        if value != original:
                            self.verify()
                            os.setxattr(target, 'system.posix_acl_access', value)
                    if stat.S_ISDIR(info.st_mode):
                        directory = os.open('.', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                        try:
                            for name in os.listdir(directory):
                                child = os.open(name, os.O_PATH | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory)
                                try: self.add_reads(child, uid, groups)
                                finally: os.close(child)
                        finally: os.close(directory)
                    self.verify()

            def sum_bits(values):
                result = 0
                for value in values: result |= value
                return result

            def content_status(path):
                env = {key: value for key, value in git_env.items() if key not in ('GIT_CONFIG_COUNT', 'GIT_CONFIG_PARAMETERS', 'GIT_ASKPASS', 'SSH_ASKPASS', 'GH_TOKEN', 'GITHUB_TOKEN') and not key.startswith(('GIT_CONFIG_KEY_', 'GIT_CONFIG_VALUE_'))}
                command = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', path, 'status', '--porcelain=v2', '--untracked-files=all']
                if not worker: return subprocess.check_output(command, stderr=subprocess.DEVNULL, env=env)
                try: account = pwd.getpwnam(worker)
                except KeyError: raise SystemExit(126)
                uid = account.pw_uid
                if uid == 0 or uid == os.geteuid(): raise SystemExit(126)
                groups = os.getgrouplist(worker, account.pw_gid)
                access = ReadGrant()
                with access.stack:
                    source = access.pin(path)
                    access.pin(path + '/.git', directory=False)
                    common_path = git(path, 'rev-parse', '--path-format=absolute', '--git-common-dir')
                    access.verify()
                    common = access.pin(common_path)
                    homes = [os.path.realpath(pwd.getpwuid(value).pw_dir) for value in (os.geteuid(), uid)]
                    if any(os.path.commonpath([directory, home]) == directory for directory in (path, common_path) for home in homes): raise SystemExit(42)
                    access.verify()
                    access.add_reads(source, uid, groups)
                    access.add_reads(common, uid, groups)
                    command = ['sudo', '-n', '-u', worker, '-H', '--', 'env', 'GIT_OPTIONAL_LOCKS=0', 'git', '-c', 'safe.directory=' + path, *command[1:]]
                    result = subprocess.check_output(command, stderr=subprocess.DEVNULL, env=env)
                    access.verify()
                    return result

            PYTHON;
    }

    private static function inspectionScript(): string
    {
        return self::workerStatusScript().<<<'PYTHON'
            import hashlib, json, os, pathlib, stat, subprocess, sys

            requested = sys.argv[1]
            include_worktrees = sys.argv[2] == '1'
            managed_user = sys.argv[3]
            managed_group = sys.argv[4]
            git_env = dict(os.environ, GIT_OPTIONAL_LOCKS='0')

            def git(path, *args):
                return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env).decode().strip()

            def digest(path):
                root = pathlib.Path(path)
                h = hashlib.sha256()
                for args in [
                    ('rev-parse', 'HEAD'),
                    ('symbolic-ref', '-q', 'HEAD'),
                    ('status', '--porcelain=v2', '--untracked-files=all'),
                    ('config', '--local', '--null', '--list'),
                    ('ls-files', '--stage', '-v'),
                ]:
                    if args[0] == 'status': value = content_status(path)
                    else:
                        try: value = subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env)
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
            origin = git(top, 'config', '--get', 'remote.origin.url')
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
        return self::workerStatusScript().<<<'PYTHON'
            import errno, hashlib, json, os, pathlib, shutil, stat, subprocess, sys
            operation = sys.argv[1]
            members = json.loads(sys.argv[2])
            git_env = dict(os.environ, GIT_OPTIONAL_LOCKS='0')

            def git(path, *args): return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', path, *args], stderr=subprocess.DEVNULL, env=git_env).decode().strip()
            def digest(path):
                root = pathlib.Path(path); h = hashlib.sha256()
                for args in [('rev-parse','HEAD'),('symbolic-ref','-q','HEAD'),('status','--porcelain=v2','--untracked-files=all'),('config','--local','--null','--list'),('ls-files','--stage','-v')]:
                    if args[0] == 'status': value = content_status(path)
                    else:
                        try: value = subprocess.check_output(['git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',path,*args], stderr=subprocess.DEVNULL, env=git_env)
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
            def remove_stage(path):
                if os.path.islink(path) or os.path.isfile(path): os.unlink(path)
                else: shutil.rmtree(path)
            def source_identity(path):
                if not os.path.isdir(path) or os.path.islink(path) or os.path.realpath(path) != path: raise SystemExit(42)
                info=os.lstat(path)
                return info.st_dev,info.st_ino
            def prepare(member):
                source=member['source']; destination=member['destination']; stage=destination+'.orbit-stage-'+str(member['id'])
                if source == destination:
                    verify(destination, member)
                    return {'id':member['id'],'source_device':None,'source_inode':None}
                pathlib.Path(destination).parent.mkdir(parents=True, exist_ok=True)
                if os.path.exists(destination):
                    verify(destination, member)
                    if os.path.exists(source):
                        verify(source, member)
                        device,inode=source_identity(source)
                    else: device,inode=None,None
                    if os.path.lexists(stage): remove_stage(stage)
                    return {'id':member['id'],'source_device':device,'source_inode':inode}
                if os.path.lexists(stage):
                    try: verify(stage, member)
                    except SystemExit:
                        if not os.path.exists(source): raise
                        verify(source, member)
                        remove_stage(stage)
                    else:
                        device,inode=source_identity(source) if os.path.exists(source) else (None,None)
                        os.rename(stage, destination)
                        verify(destination, member)
                        return {'id':member['id'],'source_device':device,'source_inode':inode}
                if os.path.exists(source): verify(source, member)
                else: raise SystemExit(42)
                device,inode=source_identity(source)
                try: os.rename(source, destination)
                except OSError as error:
                    if error.errno != errno.EXDEV: raise
                    if os.path.lexists(stage): shutil.rmtree(stage)
                    shutil.copytree(source, stage, symlinks=True, copy_function=shutil.copy2)
                    verify(stage, member)
                    os.rename(stage, destination)
                    verify(destination, member)
                verify(destination, member)
                return {'id':member['id'],'source_device':device,'source_inode':inode}
            def repair():
                mapping={m['source']:m['destination'] for m in members}
                checkouts=[m for m in members if m['layout']=='checkout']
                if checkouts:
                    checkout=checkouts[0]
                    retained=[mapping.get(path,path) for path in checkout['worktrees'] if path != checkout['source']]
                    subprocess.check_call(['git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',checkout['destination'],'worktree','repair',*retained], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                else:
                    member=members[0]
                    subprocess.check_call(['git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','--git-dir',member['common'],'worktree','repair',member['destination']], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            def cleanup(member):
                source=member['source']; destination=member['destination']; stage=destination+'.orbit-stage-'+str(member['id'])
                verify(destination, member)
                if source != destination and os.path.exists(source):
                    device,inode=source_identity(source)
                    if device != member['source_device'] or inode != member['source_inode']: raise SystemExit(42)
                if os.path.lexists(stage): remove_stage(stage)
            def remove_original(member):
                source=member['source']; destination=member['destination']
                if source == destination or not os.path.exists(source): return
                for current,dirs,files in os.walk(source, topdown=False, followlinks=False):
                    for name in files: os.unlink(os.path.join(current,name))
                    for name in dirs:
                        path=os.path.join(current,name)
                        if os.path.islink(path): os.unlink(path)
                        else: os.rmdir(path)
                os.rmdir(source)
            ordered = sorted(members, key=lambda m: 1 if m['layout'] == 'checkout' else 0)
            def close_environment():
                # Other local users, the Node agent included, never read an Instance's environment.
                for member in members:
                    env=os.path.join(member['application'],'.env')
                    if os.path.realpath(member['application']) != member['application']: raise SystemExit(42)
                    if os.path.isfile(env) and not os.path.islink(env) and os.lstat(env).st_mode & 0o007:
                        os.chmod(env, stat.S_IMODE(os.lstat(env).st_mode) & 0o770)
            if operation == 'adopt':
                if any(member['source'] != member['destination'] for member in members): raise SystemExit(42)
                for member in members: verify(member['destination'], member)
            elif operation == 'adopt-finalize':
                for member in members:
                    if member['source'] != member['destination']: raise SystemExit(42)
                    source_identity(member['destination'])
                close_environment()
            elif operation == 'prepare':
                identities=[prepare(member) for member in ordered]
                repair()
                for member in members: verify(member['destination'], member)
                print(json.dumps(identities,separators=(',',':')))
            elif operation == 'cleanup':
                for member in members: cleanup(member)
                for member in ordered: remove_original(member)
                for member in members: verify(member['destination'], member)
                close_environment()
            else: raise SystemExit(42)
            PYTHON;
    }

    private static function laravelReceiptScript(): string
    {
        return <<<'PYTHON'
            import json, os, pathlib, shutil, sys
            root=pathlib.Path(sys.argv[1]); instance=sys.argv[2]; operation=sys.argv[3]
            receipt=root.parent/('.orbit-registration-url-'+instance)
            application=pathlib.Path(sys.argv[4])
            if application.resolve() != application: raise SystemExit(42)
            paths=[application/'.env', application/'bootstrap'/'cache'/'config.php']
            directories=[application, application/'bootstrap', application/'bootstrap'/'cache']
            def validate_receipt():
                if receipt.is_symlink() or not receipt.is_dir(): raise SystemExit(42)
                manifest_path=receipt/'manifest'
                if manifest_path.is_symlink() or not manifest_path.is_file(): raise SystemExit(42)
                try: manifest=json.loads(manifest_path.read_text())
                except (OSError,ValueError,TypeError): raise SystemExit(42)
                if set(manifest) != {'files','directories'}: raise SystemExit(42)
                if not isinstance(manifest['files'],list) or len(manifest['files']) != len(paths): raise SystemExit(42)
                if any(type(value) is not bool for value in manifest['files']): raise SystemExit(42)
                if not isinstance(manifest['directories'],list) or len(manifest['directories']) != len(directories): raise SystemExit(42)
                for index,exists in enumerate(manifest['files']):
                    backup=receipt/str(index)
                    if exists != (backup.is_file() and not backup.is_symlink()): raise SystemExit(42)
                return manifest
            if operation == 'prepare':
                if receipt.exists() or receipt.is_symlink():
                    validate_receipt()
                    raise SystemExit(0)
                preparing=receipt.with_name(receipt.name+'.preparing')
                if preparing.is_symlink() or preparing.exists() and not preparing.is_dir(): raise SystemExit(42)
                if preparing.exists(): shutil.rmtree(preparing)
                preparing.mkdir(mode=0o700)
                manifest={'files': [], 'directories': []}
                for index,path in enumerate(paths):
                    exists=path.is_file() and not path.is_symlink()
                    manifest['files'].append(exists)
                    if exists: shutil.copy2(path, preparing/str(index))
                for path in directories:
                    info=path.stat() if path.is_dir() and not path.is_symlink() else None
                    manifest['directories'].append(None if info is None else [info.st_atime_ns,info.st_mtime_ns])
                (preparing/'manifest').write_text(json.dumps(manifest)); os.chmod(preparing/'manifest',0o600)
                os.replace(preparing,receipt)
                validate_receipt()
            elif operation == 'restore':
                manifest=validate_receipt()
                for index,path in enumerate(paths):
                    if manifest['files'][index]: path.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(receipt/str(index),path)
                    elif path.exists() and not path.is_symlink(): path.unlink()
                for path,times in reversed(list(zip(directories,manifest['directories']))):
                    if times is not None and path.is_dir() and not path.is_symlink(): os.utime(path,ns=tuple(times))
                shutil.rmtree(receipt)
            elif operation == 'discard':
                if receipt.exists() or receipt.is_symlink():
                    validate_receipt()
                    shutil.rmtree(receipt)
            else: raise SystemExit(42)
            PYTHON;
    }
}
