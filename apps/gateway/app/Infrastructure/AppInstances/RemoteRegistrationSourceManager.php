<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use JsonException;

/**
 * @mago-expect lint:too-many-methods The adapter keeps the fixed registration protocol and its parser together.
 * @mago-expect lint:cyclomatic-complexity The adapter validates each source and relocation state before mutation.
 */
final readonly class RemoteRegistrationSourceManager implements RegistrationSourceManager
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array
    {
        $this->assertNode($node);
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: ['python3', '-c', self::inspectionScript(), $sourcePath, $includeWorktrees ? '1' : '0'],
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

    public function relocateSet(array $members): void
    {
        if ($members === []) {
            throw new \InvalidArgumentException('A registration source set cannot be empty.');
        }

        $node = $members[0]['appInstance']->node;
        $payload = [];

        foreach ($members as $member) {
            $instance = $member['appInstance'];
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
                'layout' => $facts->layout->value,
                'commit' => $facts->commit,
                'branch' => $facts->branch,
                'detached' => $facts->detached,
                'digest' => $facts->sourceDigest,
                'common' => $facts->commonRepositoryPath,
                'worktrees' => $facts->worktreePaths,
            ];

            if ($instance->registration_relocation_state !== 'relocated') {
                AppInstance::query()
                    ->whereKey($instance->id)
                    ->update([
                        'registration_relocation_state' => 'relocating',
                        'registration_authoritative_path' => $facts->path,
                    ]);
            }
        }

        $this->ssh->execute(
            $node,
            new RemoteCommand([
                'python3',
                '-c',
                self::relocationScript(),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]),
            step: 'registration-source-relocate',
            errorCode: 'instance.registration_incomplete',
            commandTimeout: 900,
        );

        foreach ($members as $member) {
            AppInstance::query()
                ->whereKey($member['appInstance']->id)
                ->update([
                    'registration_relocation_state' => 'relocated',
                    'registration_authoritative_path' => $member['appInstance']->checkout_path,
                ]);
        }
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
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand([
                'python3',
                '-c',
                self::laravelReceiptScript(),
                $appInstance->checkout_path,
                (string) $appInstance->id,
                $operation,
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
            || (($row['detached'] ?? false) === true) !== ($branch === null)
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
            worktreePaths: array_values($worktreePaths),
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

    private function assertNode(Node $node): void
    {
        $account = $this->accounts->resolve($node);

        if ($account->user !== $node->user) {
            throw new ResourceOperationException(
                'instance.source_owner_invalid',
                'Registration requires the caller Node managed user.',
                409,
            );
        }
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
                dot_git = pathlib.Path(path) / '.git'
                layout = 'checkout' if dot_git.is_dir() and not dot_git.is_symlink() else 'worktree'
                if layout == 'worktree' and (not dot_git.is_file() or dot_git.is_symlink()): raise SystemExit(42)
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
            import errno, hashlib, json, os, pathlib, shutil, stat, subprocess, sys
            members = json.loads(sys.argv[1])
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
            def remove_stage(path):
                if os.path.islink(path) or os.path.isfile(path): os.unlink(path)
                else: shutil.rmtree(path)
            def move(member):
                source=member['source']; destination=member['destination']; stage=destination+'.orbit-stage-'+str(member['id'])
                if source == destination: verify(destination, member); return
                pathlib.Path(destination).parent.mkdir(parents=True, exist_ok=True)
                if os.path.exists(destination):
                    verify(destination, member)
                    if os.path.exists(source):
                        verify(source, member)
                        shutil.rmtree(source)
                    if os.path.lexists(stage): remove_stage(stage)
                    return
                if os.path.lexists(stage):
                    try: verify(stage, member)
                    except SystemExit:
                        if not os.path.exists(source): raise
                        verify(source, member)
                        remove_stage(stage)
                    else:
                        os.rename(stage, destination)
                        verify(destination, member)
                        if os.path.exists(source):
                            verify(source, member)
                            shutil.rmtree(source)
                        return
                if os.path.exists(source): verify(source, member)
                else: raise SystemExit(42)
                try: os.rename(source, destination)
                except OSError as error:
                    if error.errno != errno.EXDEV: raise
                    if os.path.lexists(stage): shutil.rmtree(stage)
                    shutil.copytree(source, stage, symlinks=True, copy_function=shutil.copy2)
                    verify(stage, member)
                    os.rename(stage, destination)
                    verify(destination, member)
                    shutil.rmtree(source)
                verify(destination, member)
            ordered = sorted(members, key=lambda m: 1 if m['layout'] == 'checkout' else 0)
            for member in ordered: move(member)
            mapping={m['source']:m['destination'] for m in members}
            checkouts=[m for m in members if m['layout']=='checkout']
            if checkouts:
                checkout=checkouts[0]
                retained=[mapping.get(path,path) for path in checkout['worktrees'] if path != checkout['source']]
                subprocess.check_call(['git','-C',checkout['destination'],'worktree','repair',*retained], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            else:
                member=members[0]
                subprocess.check_call(['git','--git-dir',member['common'],'worktree','repair',member['destination']], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for member in members: verify(member['destination'], member)
            PYTHON;
    }

    private static function laravelReceiptScript(): string
    {
        return <<<'PYTHON'
            import json, os, pathlib, shutil, sys
            root=pathlib.Path(sys.argv[1]); instance=sys.argv[2]; operation=sys.argv[3]
            receipt=root.parent/('.orbit-registration-url-'+instance)
            paths=[root/'.env', root/'bootstrap'/'cache'/'config.php']
            directories=[root, root/'bootstrap', root/'bootstrap'/'cache']
            if operation == 'prepare':
                if receipt.exists(): shutil.rmtree(receipt)
                receipt.mkdir(mode=0o700)
                manifest={'files': [], 'directories': []}
                for index,path in enumerate(paths):
                    exists=path.is_file() and not path.is_symlink()
                    manifest['files'].append(exists)
                    if exists: shutil.copy2(path, receipt/str(index))
                for path in directories:
                    info=path.stat() if path.is_dir() and not path.is_symlink() else None
                    manifest['directories'].append(None if info is None else [info.st_atime_ns,info.st_mtime_ns])
                (receipt/'manifest').write_text(json.dumps(manifest)); os.chmod(receipt/'manifest',0o600)
            elif operation == 'restore':
                manifest=json.loads((receipt/'manifest').read_text())
                for index,path in enumerate(paths):
                    if manifest['files'][index]: path.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(receipt/str(index),path)
                    elif path.exists() and not path.is_symlink(): path.unlink()
                for path,times in reversed(list(zip(directories,manifest['directories']))):
                    if times is not None and path.is_dir() and not path.is_symlink(): os.utime(path,ns=tuple(times))
                shutil.rmtree(receipt)
            elif operation == 'discard':
                if receipt.exists(): shutil.rmtree(receipt)
            else: raise SystemExit(42)
            PYTHON;
    }
}
