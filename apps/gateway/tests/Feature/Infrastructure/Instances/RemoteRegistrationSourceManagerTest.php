<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\SeparateFilesystem;

describe('TaskCheckWorkerUser', function (): void {
    it('inspects registration content with worker filters and preserves managed ownership', function (string $filter): void {
        $fixture = orb105_relocation_fixture(false);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            $log = orb105_worker_filter($fixture, $filter);
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

            expect($facts->path)->toBe($fixture['source'])
                ->and(fileowner($fixture['source']))->toBe(posix_geteuid())
                ->and(fileowner($fixture['source'].'/.git'))->toBe(posix_geteuid());
            $users = file($log, FILE_IGNORE_NEW_LINES) ?: [];
            expect($users)->not->toBeEmpty();
            foreach ($users as $user) {
                expect($user)->toBe('65534:absent');
            }
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['clean', 'process']);

    it('verifies registration relocation with worker filters while the managed user moves and cleans up', function (string $filter, bool $crossFilesystem): void {
        $fixture = orb105_relocation_fixture($crossFilesystem);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            $log = orb105_worker_filter($fixture, $filter);
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
            file_put_contents($log, '');
            $fixture['manager']->relocate($fixture['instance'], $facts);

            expect(file_exists($fixture['source']))->toBeFalse()
                ->and(fileowner($fixture['destination']))->toBe(posix_geteuid())
                ->and(fileowner($fixture['destination'].'/.git'))->toBe(posix_geteuid())
                ->and($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated');
            $users = file($log, FILE_IGNORE_NEW_LINES) ?: [];
            expect($users)->not->toBeEmpty();
            foreach ($users as $user) {
                expect($user)->toBe('65534:absent');
            }
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with([['clean', false], ['process', false], ['clean', true], ['process', true]]);

    it('fails closed on a failed worker switch or content-status command', function (string $phase, string $failure): void {
        $fixture = orb105_relocation_fixture(false);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            $log = orb105_worker_filter($fixture, 'clean');
            $facts = $phase === 'relocation' ? $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0] : null;
            file_put_contents($log, '');
            if ($failure === 'sudo') {
                $bin = $fixture['source_root'].'/bin';
                new Filesystem()->ensureDirectoryExists($bin);
                file_put_contents($bin.'/sudo', "#!/bin/sh\nexit 1\n");
                chmod($bin.'/sudo', 0o755);
                $manager = orb105_registration_manager(new Orb105LocalSshExecutor(['PATH' => $bin.':'.getenv('PATH')]));
            } else {
                orb105_git($fixture['source'], ['config', 'filter.worker.clean', 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> '.escapeshellarg($log).'; exit 1']);
                $manager = $fixture['manager'];
            }

            $operation = $facts === null
                ? fn () => $manager->inspect($fixture['node'], $fixture['source'], false)
                : fn () => $manager->relocate($fixture['instance'], $facts);
            expect($operation)->toThrow(RuntimeConvergenceException::class)
                ->and(is_dir($fixture['source']))->toBeTrue()
                ->and(file_exists($fixture['destination']))->toBeFalse();
            if ($failure === 'sudo') {
                expect(file_get_contents($log))->toBe('');
            } else {
                expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['65534:absent']);
            }
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with([['inspection', 'sudo'], ['relocation', 'sudo'], ['inspection', 'status'], ['relocation', 'status']]);

    it('grants required worker reads without following source links to private files', function (string $linkType): void {
        $fixture = orb105_relocation_fixture(false);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            $log = orb105_worker_filter($fixture, 'clean');
            $private = $fixture['source_root'].'/private';
            file_put_contents($private, 'private Node contents');
            chmod($private, 0o600);
            if ($linkType === 'symlink') {
                symlink($private, $fixture['source'].'/private-link');
            } else {
                link($private, $fixture['source'].'/private-link');
            }
            $fixture['manager']->inspect($fixture['node'], $fixture['source'], false);

            expect(trim((string) file_get_contents($log)))->toBe('65534:absent')
                ->and(orb105_run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'test', '-r', $private])->succeeded())->toBeFalse()
                ->and(file_get_contents($private))->toBe('private Node contents')
                ->and(fileperms($private) & 0o777)->toBe(0o600);
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['symlink', 'hardlink']);

    it('refuses registration inspection when the configured worker is unavailable', function (): void {
        $fixture = orb105_relocation_fixture(false);

        try {
            $log = orb105_worker_filter($fixture, 'clean');
            config()->set('orbit.tasks.worker_user', 'orbit-absent-task-worker');

            expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], false))
                ->toThrow(RuntimeConvergenceException::class)
                ->and(file_get_contents($log))->toBe('')
                ->and(is_dir($fixture['source']))->toBeTrue();
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    });

    it('refuses relocation when the worker disappears after inspection, without Node filter execution or source deletion', function (): void {
        $fixture = orb105_relocation_fixture(false);

        try {
            $log = orb105_worker_filter($fixture, 'process');
            config()->set('orbit.tasks.worker_user', 'nobody');
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
            file_put_contents($log, '');
            config()->set('orbit.tasks.worker_user', 'orbit-absent-task-worker');

            expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
                ->toThrow(RuntimeConvergenceException::class)
                ->and(file_get_contents($log))->toBe('')
                ->and(is_dir($fixture['source']))->toBeTrue()
                ->and(file_exists($fixture['destination']))->toBeFalse()
                ->and($fixture['instance']->refresh()->registration_authoritative_path)->toBe($fixture['source']);
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    });
});

describe('TaskGitHardening', function (): void {
    it('rejects worker directory substitution without granting private Node access', function (string $phase, string $swap): void {
        $fixture = orb105_relocation_fixture(false);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            $separateCommon = $fixture['source'].'/.git';
            if ($swap === 'worktree-common') {
                $linked = $fixture['source_root'].'/linked';
                expect(orb105_git($fixture['source'], ['worktree', 'add', '-b', 'linked', $linked])->succeeded())->toBeTrue();
                $fixture['source'] = $linked;
                $fixture['instance']->update(['source_layout' => 'worktree', 'registration_original_path' => $linked, 'registration_authoritative_path' => $linked]);
            }
            orb105_worker_filter($fixture, 'clean');
            $privateHome = $fixture['source_root'].'/private-home';
            $private = $privateHome.'/.ssh';
            new Filesystem()->ensureDirectoryExists($private);
            chmod($privateHome, 0o711);
            chmod($private, 0o700);
            file_put_contents($private.'/key', 'private Node key');
            chmod($private.'/key', 0o600);
            $before = orb105_run(['getfacl', '-cpn', '--', $privateHome, $private, $private.'/key'])->stdout;
            $facts = $phase === 'relocation' ? $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0] : null;
            $target = $swap === 'worktree-common' ? $separateCommon : ($swap === 'source' ? $fixture['source'] : $fixture['source'].'/.git');
            expect(orb105_run(['setfacl', '-m', 'u:nobody:rwx', '--', dirname($target)])->succeeded())->toBeTrue();
            $attackerLog = $fixture['source_root'].'/attacker';
            file_put_contents($attackerLog, '');
            expect(orb105_run(['setfacl', '-m', 'u:nobody:rw', '--', $attackerLog])->succeeded())->toBeTrue();
            $manager = orb105_registration_manager(new Orb105DirectorySwapSshExecutor($target, $private, $attackerLog, $phase === 'inspection' ? 2 : 1));
            $operation = $facts === null
                ? fn () => $manager->inspect($fixture['node'], $fixture['source'], false)
                : fn () => $manager->relocate($fixture['instance'], $facts);

            expect($operation)->toThrow(RuntimeConvergenceException::class)
                ->and(file_get_contents($attackerLog))->toBe('65534')
                ->and(orb105_run(['getfacl', '-cpn', '--', $privateHome, $private, $private.'/key'])->stdout)->toBe($before)
                ->and(orb105_run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'test', '-r', $private.'/key'])->succeeded())->toBeFalse()
                ->and(file_get_contents($private.'/key'))->toBe('private Node key');
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with([['inspection', 'source'], ['inspection', 'common'], ['inspection', 'worktree-common'], ['relocation', 'source'], ['relocation', 'common'], ['relocation', 'worktree-common']]);
});

describe('TaskCheckWorkerUser', function (): void {
    it('preserves workspace writes and Git locks after registration reads or failures', function (string $phase): void {
        $fixture = orb105_relocation_fixture(false);
        config()->set('orbit.tasks.worker_user', 'nobody');

        try {
            orb105_worker_filter($fixture, 'clean');
            expect(orb105_run(['setfacl', '-R', '-P', '-m', 'u:nobody:rwX,u:'.posix_geteuid().':rwX', '--', $fixture['source']])->succeeded())->toBeTrue();
            expect(orb105_run(['setfacl', '-m', 'd:u:nobody:rwx,d:u:'.posix_geteuid().':rwx', '--', $fixture['source'], $fixture['source'].'/.git'])->succeeded())->toBeTrue();
            // A raw write bit masked to read-only must not become effective.
            expect(orb105_run(['setfacl', '-m', 'u:nobody:rw-,m::r--', '--', $fixture['source'].'/.git/config'])->succeeded())->toBeTrue();
            $defaultBefore = orb105_run(['getfacl', '-cdn', '--', $fixture['source'], $fixture['source'].'/.git'])->stdout;
            $facts = str_contains($phase, 'relocation') ? $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0] : null;
            if (str_starts_with($phase, 'failed')) {
                $bin = $fixture['source_root'].'/bin';
                new Filesystem()->ensureDirectoryExists($bin);
                file_put_contents($bin.'/sudo', "#!/bin/sh\nexit 1\n");
                chmod($bin.'/sudo', 0o755);
                $manager = orb105_registration_manager(new Orb105LocalSshExecutor(['PATH' => $bin.':'.getenv('PATH')]));
                expect($facts === null
                    ? fn () => $manager->inspect($fixture['node'], $fixture['source'], false)
                    : fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class);
                $path = $fixture['source'];
            } elseif ($facts === null) {
                $fixture['manager']->inspect($fixture['node'], $fixture['source'], false);
                $path = $fixture['source'];
            } else {
                $fixture['manager']->relocate($fixture['instance'], $facts);
                $path = $fixture['destination'];
            }
            $write = orb105_run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'bash', '-ceu', 'printf edited > "$1/README.md"; printf created > "$1/worker-created"; printf locked > "$1/.git/index.lock"; rm -- "$1/.git/index.lock"', '--', $path]);

            expect($write->succeeded())->toBeTrue($write->stderr)
                ->and(file_get_contents($path.'/README.md'))->toBe('edited')
                ->and(file_get_contents($path.'/worker-created'))->toBe('created')
                ->and(file_exists($path.'/.git/index.lock'))->toBeFalse()
                ->and(orb105_run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'test', '-w', $path.'/.git/config'])->succeeded())->toBeFalse()
                ->and(orb105_run(['getfacl', '-cdn', '--', $path, $path.'/.git'])->stdout)->toBe($defaultBefore);
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['inspection', 'relocation', 'failed-inspection', 'failed-relocation']);
});

final readonly class Orb105DirectorySwapSshExecutor implements SshExecutor
{
    public function __construct(private string $target, private string $private, private string $log, private int $discovery) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $payload = base64_encode(json_encode([$this->target, $this->private, $this->log, $this->discovery], JSON_THROW_ON_ERROR));
        $hook = <<<'PYTHON'
            import base64, json, subprocess
            swap_target, swap_private, swap_log, swap_discovery = json.loads(base64.b64decode('__PAYLOAD__'))
            original_check_output = subprocess.check_output
            discoveries = 0
            def after_discovery(arguments, **kwargs):
                global discoveries
                output = original_check_output(arguments, **kwargs)
                if arguments[0] == 'git' and arguments[-1] == '--git-common-dir':
                    discoveries += 1
                    if discoveries == swap_discovery:
                        subprocess.run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'python3', '-Ic', 'import os,sys; os.rename(sys.argv[1],sys.argv[1]+"-original"); os.symlink(sys.argv[2],sys.argv[1]); open(sys.argv[3],"w").write(str(os.geteuid()))', swap_target, swap_private, swap_log], check=True)
                return output
            subprocess.check_output = after_discovery

            PYTHON;
        $arguments = $command->arguments;
        $arguments[2] = str_replace('__PAYLOAD__', $payload, $hook).$arguments[2];

        return new Orb105LocalSshExecutor()->execute($connection, new RemoteCommand($arguments));
    }
}

/** @param array{source: string, source_root: string} $fixture */
function orb105_worker_filter(array $fixture, string $filter): string
{
    $source = $fixture['source'];
    $log = $fixture['source_root'].'/filter-users';
    file_put_contents($log, '');
    expect(orb105_run(['setfacl', '-m', 'u:nobody:rw', '--', $log])->succeeded())->toBeTrue();
    file_put_contents($source.'/.gitattributes', "README.md filter=worker\n");
    orb105_git($source, ['add', '.gitattributes']);
    orb105_git($source, ['commit', '-m', 'Filter attributes']);
    if ($filter === 'clean') {
        orb105_git($source, ['config', 'filter.worker.clean', 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> '.escapeshellarg($log).'; cat']);
    } else {
        $driver = $fixture['source_root'].'/filter.py';
        file_put_contents($driver, <<<'PYTHON'
            import os, sys
            source, sink = sys.stdin.buffer, sys.stdout.buffer
            def packet():
                size = source.read(4)
                if not size: raise EOFError
                length = int(size, 16)
                return None if length == 0 else source.read(length - 4)
            def section():
                values = []
                while True:
                    value = packet()
                    if value is None: return values
                    values.append(value)
            def write(values):
                for value in values: sink.write(('%04x' % (len(value) + 4)).encode() + value)
                sink.write(b'0000'); sink.flush()
            section(); write([b'git-filter-server\n', b'version=2\n'])
            section(); write([b'capability=clean\n', b'capability=smudge\n'])
            try:
                while True:
                    section(); content = section()
                    with open(sys.argv[1], 'a') as log: log.write(str(os.geteuid()) + ':' + os.environ.get('GIT_CONFIG_VALUE_0', 'absent') + '\n')
                    write([b'status=success\n']); write(content); write([])
            except EOFError: pass
            PYTHON);
        orb105_git($source, ['config', 'filter.worker.process', 'python3 -I '.escapeshellarg($driver).' '.escapeshellarg($log)]);
    }
    orb105_git($source, ['config', 'filter.worker.required', 'true']);
    // Same size as the indexed blob forces status to hash through the filter.
    file_put_contents($source.'/README.md', "changed\n");
    touch($source.'/README.md', time() - 10);
    $common = trim(orb105_git($source, ['rev-parse', '--path-format=absolute', '--git-common-dir'])->stdout);
    chmod($source, 0o700);
    chmod($source.'/.git', 0o700);
    chmod($common, 0o700);
    chmod($common.'/config', 0o600);

    return $log;
}

it('resumes relocation from each durable cross-filesystem checkpoint', function (string $checkpoint): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;

        if ($checkpoint === 'incomplete-stage') {
            orb105_copy_tree($fixture['source'], $stage);
            file_put_contents($stage.'/README.md', "partial stage\n");
        } elseif ($checkpoint === 'complete-stage') {
            orb105_copy_tree($fixture['source'], $stage);
        } else {
            orb105_copy_tree($fixture['source'], $fixture['destination']);

            if ($checkpoint === 'destination-only') {
                new Filesystem()->deleteDirectory($fixture['source']);
            }
        }

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($stage))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with([
    'incomplete stage with the verified original' => 'incomplete-stage',
    'complete verified stage with the verified original' => 'complete-stage',
    'verified destination with a duplicate original' => 'destination-and-original',
    'verified destination after original removal before the database checkpoint' => 'destination-only',
]);

describe('in-place registration with shared refs', function (): void {
    it('adopts a managed worktree while unrelated refs change', function (): void {
        $fixture = orb918_in_place_fixture();

        try {
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0];
            $identity = stat($fixture['destination']);
            $gitLink = file_get_contents($fixture['destination'].'/.git');
            $transport = new Orb918ConcurrentRefsSshExecutor;
            $manager = orb105_registration_manager($transport);

            $manager->relocate($fixture['instance'], $facts);

            expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
                ->and(stat($fixture['destination'])['ino'])->toBe($identity['ino'])
                ->and(file_get_contents($fixture['destination'].'/.git'))->toBe($gitLink)
                ->and($fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0]->sourceDigest)->toBe($facts->sourceDigest)
                ->and(trim(orb105_git($fixture['source'], ['rev-parse', 'refs/t3/test/918/1'])->stdout))->toBe($facts->commit)
                ->and($transport->operations)->not->toContain('prepare', 'cleanup');
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    });

    it('resumes an interrupted adoption after unrelated refs change', function (string $checkpoint): void {
        $fixture = orb918_in_place_fixture();
        file_put_contents($fixture['destination'].'/.env', "APP_KEY=private\n");
        chmod($fixture['destination'].'/.env', 0o644);
        $transport = new Orb918InterruptAdoptionSshExecutor($checkpoint);
        $manager = orb105_registration_manager($transport);

        try {
            $facts = $manager->inspect($fixture['node'], $fixture['destination'], false)[0];
            expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class);
            orb105_git($fixture['source'], ['update-ref', 'refs/t3/test/retry', $facts->commit]);

            if ($checkpoint === 'adopt') {
                $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']);
            } else {
                $manager->validateRetained($fixture['node'], $facts, $fixture['destination']);
            }
            $manager->relocate($fixture['instance']->refresh(), $facts);

            expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
                ->and(fileperms($fixture['destination'].'/.env') & 0o007)->toBe(0)
                ->and($transport->operations)->not->toContain('prepare', 'cleanup');
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['adopt', 'adopt-finalize']);

    it('refuses changed source state before adoption', function (string $change): void {
        $fixture = orb918_in_place_fixture();

        try {
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0];
            if ($change === 'index') {
                orb105_git($fixture['destination'], ['update-index', '--assume-unchanged', 'README.md']);
            } elseif ($change === 'head') {
                orb105_git($fixture['destination'], ['commit', '--allow-empty', '-m', 'Source changed']);
            } else {
                file_put_contents($fixture['destination'].'/README.md', 'changed');
            }

            expect(fn () => $fixture['manager']->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']))->toThrow(ResourceOperationException::class);
            expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class)
                ->and(is_dir($fixture['destination']))->toBeTrue();
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['head', 'index', 'working-tree']);
});

it('resumes a relocated checkout after unrelated repository refs change', function (): void {
    $fixture = orb105_relocation_fixture(false);
    $manager = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);

    try {
        $facts = $manager->inspect($fixture['node'], $fixture['source'], false)[0];
        expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class);
        orb105_git($fixture['destination'], ['update-ref', 'refs/t3/test/relocation-retry', $facts->commit]);

        $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']);
        $manager->relocate($fixture['instance']->refresh(), $facts);

        expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
            ->and(is_dir($fixture['source']))->toBeFalse()
            ->and(trim(orb105_git($fixture['destination'], ['rev-parse', 'HEAD'])->stdout))->toBe($facts->commit);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a relocated checkout whose own HEAD changes during the move', function (): void {
    $fixture = orb105_relocation_fixture(false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $manager = orb105_registration_manager(new Orb918ChangeHeadAfterRenameSshExecutor);

        expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class)
            ->and(is_dir($fixture['destination']))->toBeTrue()
            ->and($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocating');
        expect(fn () => $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']))->toThrow(ResourceOperationException::class);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('reports the configured origin, not the insteadOf rewrite Git applies', function (): void {
    $fixture = orb105_relocation_fixture(false);
    orb105_run(['git', '-C', $fixture['source'], 'config', 'url.git@example.test:.insteadOf', 'https://example.test/']);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

        expect($facts->repositoryUrl)->toBe('https://example.test/acme.git');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('fails closed when an incomplete stage has no verified original', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;
        orb105_copy_tree($fixture['source'], $stage);
        file_put_contents($stage.'/README.md', "partial stage\n");
        new Filesystem()->deleteDirectory($fixture['source']);

        expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(is_dir($stage))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocating',
                'registration_authoritative_path' => $fixture['source'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes after an emitted same-filesystem rename outruns its database checkpoint', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['source']);

        $fixture['manager']->validateRelocationRecovery(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        );
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes a partially renamed included worktree set from retained evidence', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        $linkedDestination = $fixture['destination_root'].'/managed/acme/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        $files = new Filesystem;
        $files->ensureDirectoryExists($linkedSource.'/many');
        for ($index = 0; $index < 2_000; $index++) {
            file_put_contents($linkedSource.'/many/'.$index, "retained\n");
        }

        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], true);
        $requestId = $fixture['instance']->registration_request_id;
        $linked = Instance::query()->create([
            'project_id' => $fixture['instance']->project_id,
            'node_id' => $fixture['node']->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $linkedDestination,
            'branch' => 'feature',
            'starting_commit' => collect($facts)->firstWhere('path', $linkedSource)?->commit,
            'registration_original_path' => $linkedSource,
            'registration_request_id' => $requestId,
            'registration_repository_url' => 'https://example.test/acme.git',
            'registration_repository_identity' => 'example.test/acme',
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $linkedSource,
            'status' => 'reserved',
        ]);
        $members = array_map(
            static fn (RegistrationSourceFacts $fact): array => [
                'instance' => $fact->path === $fixture['source'] ? $fixture['instance'] : $linked,
                'facts' => $fact,
            ],
            $facts,
        );
        $manifests = [];
        foreach ($facts as $fact) {
            $manifests[$fact->path] = orb105_preserved_manifest($fact->path);
        }
        $interrupting = orb105_registration_manager(new Orb105InterruptPartialPrepareSshExecutor(
            movedSource: $linkedSource,
            unmovedSource: $fixture['source'],
        ));

        expect(fn () => $interrupting->relocateSet($members))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(is_dir($linkedDestination))
            ->toBeTrue()
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocating');

        foreach ($facts as $fact) {
            $fixture['manager']->validateRelocationRecovery(
                $fixture['node'],
                $fact,
                $fact->path === $linkedSource ? $linkedDestination : $fixture['source'],
            );
        }

        $fixture['manager']->relocateSet($members);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(orb105_preserved_manifest($fixture['destination']))
            ->toBe($manifests[$fixture['source']])
            ->and(orb105_preserved_manifest($linkedDestination))
            ->toBe($manifests[$linkedSource])
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocated');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses unsafe checkout and Git administration modes before relocation', function (string $target): void {
    $fixture = orb105_relocation_fixture();

    try {
        chmod($target === 'checkout' ? $fixture['source'] : $fixture['source'].'/.git', 0o777);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('reserved');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['checkout', 'Git administration']);

it('refuses an invalid second non-Composer worktree before any included source moves', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        chmod($linkedSource.'/.git', 0o666);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], true))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(is_dir($linkedSource))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(file_exists($fixture['destination_root'].'/managed/acme/feature'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a non-Composer checkout outside the resolved managed group', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $manager = orb105_registration_manager(new Orb105LocalSshExecutor, managedGroup: 'root');

        expect(fn () => $manager->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('persists relocation checkpoints without publishing dirty migration fields', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        Instance::query()
            ->whereKey($fixture['instance']->id)
            ->update([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
            ]);
        $fixture['instance']->refresh();
        $fixture['instance']->name = 'default';
        $fixture['instance']->checkout_path = $fixture['destination'];

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect($fixture['instance']
            ->refresh()
            ->only([
                'name',
                'checkout_path',
                'registration_relocation_state',
                'registration_authoritative_path',
            ]))->toBe([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('does not reapply source-digest relocation after the managed destination becomes authoritative', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        file_put_contents($fixture['destination'].'/.env', "APP_URL=https://managed.test\n");
        file_put_contents($fixture['destination'].'/README.md', "normal development edit\n", FILE_APPEND);
        $afterEdits = orb105_complete_manifest($fixture['destination']);

        $fixture['manager']->validateRetained($fixture['node'], $facts, $fixture['destination']);
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(orb105_complete_manifest($fixture['destination']))
            ->toBe($afterEdits)
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a replacement repository at a retained authoritative destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        orb105_run([
            'git',
            '-C',
            $fixture['destination'],
            'remote',
            'set-url',
            'origin',
            'https://example.test/replacement.git',
        ]);

        expect(fn () => $fixture['manager']->validateRetained(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.registration_conflict');
        });
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes an actual interruption during original cleanup from the verified destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture['source'].'/cleanup');
        file_put_contents($fixture['source'].'/cleanup/00000-trigger', "trigger\n");
        for ($index = 1; $index <= 20_000; $index++) {
            file_put_contents(
                $fixture['source'].'/cleanup/'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                "retained\n",
            );
        }
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(
            new Orb105InterruptingCleanupSshExecutor(
                $fixture['source'],
                $fixture['source'].'/cleanup/00000-trigger',
            ),
        );

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });

        $partialCount = count(glob($fixture['source'].'/cleanup/*') ?: []);
        $checkpoint = $fixture['instance']->refresh();
        expect(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($partialCount)
            ->toBeLessThan(20_001)
            ->and(orb105_complete_manifest($fixture['source']))
            ->not
            ->toBe($manifestBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($checkpoint->registration_relocation_state)
            ->toBe('original_cleanup')
            ->and($checkpoint->registration_authoritative_path)
            ->toBe($fixture['destination'])
            ->and($checkpoint->registration_source_device)
            ->toBeInt()
            ->and($checkpoint->registration_source_inode)
            ->toBeInt();

        $instanceId = $checkpoint->id;
        $fixture['manager']->relocate($checkpoint, $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->id)
            ->toBe($instanceId)
            ->and($fixture['instance']->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses cleanup when the original path was replaced after destination verification', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $sourceIdentity = stat($fixture['source']);
        orb105_copy_tree($fixture['source'], $fixture['destination']);
        $fixture['instance']->update([
            'registration_relocation_state' => 'original_cleanup',
            'registration_authoritative_path' => $fixture['destination'],
            'registration_source_device' => $sourceIdentity['dev'],
            'registration_source_inode' => $sourceIdentity['ino'],
        ]);
        new Filesystem()->deleteDirectory($fixture['source']);
        new Filesystem()->ensureDirectoryExists($fixture['source']);
        file_put_contents($fixture['source'].'/unrelated.txt', "unrelated\n");

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_get_contents($fixture['source'].'/unrelated.txt'))
            ->toBe("unrelated\n")
            ->and(is_dir($fixture['destination']))
            ->toBeTrue();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('restores Laravel URL files and the stable directory timestamps they touch', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture['source'].'/bootstrap/cache');
        file_put_contents($fixture['source'].'/.env', "APP_URL=https://before.test\n");
        file_put_contents($fixture['source'].'/bootstrap/cache/config.php', "<?php return ['url' => 'before'];\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source']]);
        $before = orb105_complete_manifest($fixture['source']);

        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        file_put_contents($fixture['source'].'/.env.next', "APP_URL=https://after.test\n");
        rename($fixture['source'].'/.env.next', $fixture['source'].'/.env');
        file_put_contents($fixture['source'].'/bootstrap/cache/config.php.next', "<?php return ['url' => 'after'];\n");
        rename(
            $fixture['source'].'/bootstrap/cache/config.php.next',
            $fixture['source'].'/bootstrap/cache/config.php',
        );
        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        $fixture['manager']->restoreLaravelConfiguration($fixture['instance']);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses an incomplete Laravel rollback receipt without replacing it', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $receipt = dirname($fixture['source']).'/.orbit-registration-url-'.$fixture['instance']->id;
        new Filesystem()->ensureDirectoryExists($receipt);
        file_put_contents($receipt.'/0', "partial\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source']]);

        expect(fn () => $fixture['manager']->prepareLaravelRollback($fixture['instance']))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.laravel_rollback_failed');
            })
            ->and(file_get_contents($receipt.'/0'))
            ->toBe("partial\n")
            ->and(file_exists($receipt.'/manifest'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

/**
 * @return array{manager: RemoteRegistrationSourceManager, node: Node, instance: Instance, source_root: string, destination_root: string, source: string, destination: string}
 */
function orb105_relocation_fixture(bool $crossFilesystem = true): array
{
    $token = (string) Str::uuid();
    $sourceRoot = $crossFilesystem
        ? SeparateFilesystem::path('orbit-orb105-'.$token)
        : sys_get_temp_dir().'/orbit-orb105-'.$token;
    $destinationRoot = $crossFilesystem
        ? sys_get_temp_dir().'/orbit-orb105-'.$token
        : $sourceRoot;
    $source = $sourceRoot.($crossFilesystem ? '/acme' : '/source/acme');
    $destination = $destinationRoot.'/managed/acme/default';
    $files = new Filesystem;
    $files->ensureDirectoryExists($source);
    $files->ensureDirectoryExists(dirname($destination));

    if ($crossFilesystem) {
        expect(stat($sourceRoot)['dev'])->not->toBe(stat($destinationRoot)['dev']);
    } else {
        expect(stat($sourceRoot)['dev'])->toBe(stat($destinationRoot)['dev']);
    }

    orb105_run(['git', 'init', '--initial-branch=main', $source]);
    orb105_run(['git', '-C', $source, 'config', 'user.email', 'orb105@example.test']);
    orb105_run(['git', '-C', $source, 'config', 'user.name', 'ORB-105']);
    orb105_run(['git', '-C', $source, 'config', 'orbit.fixture', 'preserved']);
    file_put_contents($source.'/README.md', "initial\n");
    $files->ensureDirectoryExists($source.'/bin');
    file_put_contents($source.'/bin/run', "#!/bin/sh\nexit 0\n");
    chmod($source.'/bin/run', 0o750);
    symlink('README.md', $source.'/readme-link');
    orb105_run(['git', '-C', $source, 'add', '.']);
    orb105_run(['git', '-C', $source, 'commit', '-m', 'Initial']);
    orb105_run(['git', '-C', $source, 'remote', 'add', 'origin', 'https://example.test/acme.git']);
    file_put_contents($source.'/README.md', "dirty\n", FILE_APPEND);
    file_put_contents($source.'/staged.txt', "staged\n");
    orb105_run(['git', '-C', $source, 'add', 'staged.txt']);
    file_put_contents($source.'/untracked.txt', "untracked\n");

    $node = Node::query()->create([
        'name' => 'orb105-local',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.105',
        'wireguard_ip' => '127.0.0.1',
        'user' => orb105_effective_user(),
        'settings' => ['apps' => ['path' => $destinationRoot.'/managed']],
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => null,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => $destination,
        'branch' => 'main',
        'registration_original_path' => $source,
        'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true,
        'registration_repository_url' => 'https://example.test/acme.git',
        'registration_repository_identity' => 'example.test/acme',
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $source,
        'status' => 'reserved',
    ]);
    $manager = orb105_registration_manager(new Orb105LocalSshExecutor);

    return
        compact(
            'manager',
            'node',
            'instance',
            'sourceRoot',
            'destinationRoot',
            'source',
            'destination',
        )
        + [
            'source_root' => $sourceRoot,
            'destination_root' => $destinationRoot,
        ];
}

/** Returns the account that creates the fixture files, not the PHP script's owner. */
function orb105_effective_user(): string
{
    $user = posix_getpwuid(posix_geteuid());

    if (! is_array($user) || ! is_string($user['name'] ?? null)) {
        throw new RuntimeException('The test process user is unavailable.');
    }

    return $user['name'];
}

/**
 * Returns the group that owns the fixture files: the test process's primary group.
 *
 * Ubuntu gives each managed account a private group with the account's name, but macOS puts users in staff.
 */
function orb918_in_place_fixture(): array
{
    $fixture = orb105_relocation_fixture(false);
    orb105_git($fixture['source'], ['worktree', 'add', '-b', 'task-918', $fixture['destination']]);
    $fixture['instance']->update([
        'source_layout' => 'worktree',
        'branch' => 'task-918',
        'registration_original_path' => $fixture['destination'],
        'registration_authoritative_path' => $fixture['destination'],
    ]);

    return $fixture;
}

function orb105_primary_group(): string
{
    $group = posix_getgrgid(posix_getegid());

    if (! is_array($group) || ! is_string($group['name'] ?? null)) {
        throw new RuntimeException('The test process group is unavailable.');
    }

    return $group['name'];
}

function orb105_registration_manager(
    SshExecutor $executor,
    ?string $managedGroup = null,
): RemoteRegistrationSourceManager {
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/dev/null';
        }

        public function publicKey(): string
        {
            return 'unused';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/dev/null';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $accounts = new class($managedGroup) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ?string $managedGroup,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount($node->user, $this->managedGroup ?? orb105_primary_group(), '/tmp');
        }
    };
    $manager = new RemoteRegistrationSourceManager(
        new DevelopmentSshExecutor($executor, $keys, $knownHosts),
        $accounts,
    );

    return $manager;
}

/** @param array{source_root: string, destination_root: string} $fixture */
function orb105_remove_relocation_fixture(array $fixture): void
{
    $files = new Filesystem;
    $files->deleteDirectory($fixture['source_root']);
    $files->deleteDirectory($fixture['destination_root']);
}

function orb105_copy_tree(string $source, string $destination): void
{
    $result = orb105_run(['cp', '-a', '--', $source, $destination]);
    expect($result->succeeded())->toBeTrue($result->stderr);
}

/** @return array<string, string> */
function orb105_git_state(string $path): array
{
    return [
        'head' => trim(orb105_git($path, ['rev-parse', 'HEAD'])->stdout),
        'branch' => trim(orb105_git($path, ['symbolic-ref', '-q', 'HEAD'])->stdout),
        'index' => hash_file('sha256', $path.'/.git/index'),
        'status' => orb105_git($path, ['status', '--porcelain=v2', '--untracked-files=all'])->stdout,
        'config' => orb105_git($path, ['config', '--local', '--null', '--list'])->stdout,
        'refs' => orb105_git($path, ['show-ref', '--head'])->stdout,
    ];
}

/** @param non-empty-list<string> $arguments */
function orb105_git(string $path, array $arguments): CommandResult
{
    return orb105_run(['env', 'GIT_OPTIONAL_LOCKS=0', 'git', '-C', $path, ...$arguments]);
}

/** @return list<array<string, int|string|null>> */
function orb105_complete_manifest(string $path): array
{
    $script = <<<'PYTHON'
        import hashlib, json, os, pathlib, stat, sys
        root = pathlib.Path(sys.argv[1])
        rows = []
        for entry in [root, *sorted(root.rglob('*'))]:
            info = entry.lstat()
            relative = '.' if entry == root else entry.relative_to(root).as_posix()
            kind = 'link' if entry.is_symlink() else 'file' if entry.is_file() else 'directory'
            rows.append({
                'path': relative,
                'type': kind,
                'mode': stat.S_IMODE(info.st_mode),
                'uid': info.st_uid,
                'gid': info.st_gid,
                'size': info.st_size if kind != 'directory' else None,
                'mtime_ns': info.st_mtime_ns,
                'content': hashlib.sha256(entry.read_bytes()).hexdigest() if kind == 'file' else None,
                'target': os.readlink(entry) if kind == 'link' else None,
            })
        print(json.dumps(rows, sort_keys=True, separators=(',', ':')))
        PYTHON;
    $result = orb105_run(['python3', '-c', $script, $path]);
    expect($result->succeeded())->toBeTrue($result->stderr);

    return json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, int|string|null>> */
function orb105_preserved_manifest(string $path): array
{
    return array_values(array_filter(
        orb105_complete_manifest($path),
        static fn (array $entry): bool => $entry['path'] !== '.git'
        && ! str_starts_with((string) $entry['path'], '.git/'),
    ));
}

/** @param non-empty-list<string> $arguments */
function orb105_run(array $arguments): CommandResult
{
    return new NativeProcessRunner(maxOutputBytes: 16_777_216)->run(new ProcessInvocation($arguments));
}

final readonly class Orb105LocalSshExecutor implements SshExecutor
{
    /** @param array<string, string> $environment */
    public function __construct(private array $environment = []) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $command->arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
            environment: $this->environment,
        ));
    }
}

final class Orb918ConcurrentRefsSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $operations = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $operation = $command->arguments[3] ?? '';
        $this->operations[] = $operation;
        if (in_array($operation, ['adopt', 'prepare'], true)) {
            $arguments = $command->arguments;
            $hook = <<<'PYTHON'
                import json, subprocess, sys
                concurrent_member = json.loads(sys.argv[2])[0]
                original_check_output = subprocess.check_output
                checkpoint = 0
                def concurrent_refs(arguments, **kwargs):
                    global checkpoint
                    output = original_check_output(arguments, **kwargs)
                    if arguments[0] == 'git':
                        checkpoint += 1
                        original_check_output(['git', '-C', concurrent_member['source'], 'update-ref', 'refs/t3/test/918/' + str(checkpoint), concurrent_member['commit']])
                        original_check_output(['git', '-C', concurrent_member['source'], 'update-ref', 'refs/heads/another-agent', concurrent_member['commit']])
                    return output
                subprocess.check_output = concurrent_refs

                PYTHON;
            $arguments[2] = $hook.$arguments[2];
            $command = new RemoteCommand(arguments: $arguments);
        }

        return new Orb105LocalSshExecutor()->execute($connection, $command);
    }
}

final class Orb918InterruptAdoptionSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    /** @var list<string> */
    public array $operations = [];

    public function __construct(private readonly string $checkpoint = 'adopt') {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $operation = $command->arguments[3] ?? '';
        $this->operations[] = $operation;
        $result = new Orb105LocalSshExecutor()->execute($connection, $command);
        if (! $this->interrupted && ($operation === $this->checkpoint || $operation === 'prepare') && $result->succeeded()) {
            $this->interrupted = true;

            return new CommandResult(137, $result->stdout, 'Interrupted adoption', $result->durationMs, false);
        }

        return $result;
    }
}

final readonly class Orb918ChangeHeadAfterRenameSshExecutor implements SshExecutor
{
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if (($command->arguments[3] ?? null) === 'prepare') {
            $arguments = $command->arguments;
            $arguments[2] = str_replace(
                'os.rename(source, destination)',
                "os.rename(source, destination); subprocess.check_call(['git','-C',destination,'commit','--allow-empty','-m','Concurrent source change'], stdout=subprocess.DEVNULL)",
                $arguments[2],
            );
            $command = new RemoteCommand(arguments: $arguments);
        }

        return new Orb105LocalSshExecutor()->execute($connection, $command);
    }
}

final class Orb105InterruptAfterPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $result = new Orb105LocalSshExecutor()->execute($connection, $command);

        if (! $this->interrupted && ($command->arguments[3] ?? null) === 'prepare' && $result->succeeded()) {
            $this->interrupted = true;

            return new CommandResult(
                exitCode: 137,
                stdout: $result->stdout,
                stderr: 'Simulated process stop after remote prepare completed.',
                durationMs: $result->durationMs,
                truncated: $result->truncated,
            );
        }

        return $result;
    }
}

final class Orb105InterruptPartialPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $movedSource,
        private readonly string $unmovedSource,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->interrupted || ($command->arguments[3] ?? null) !== 'prepare') {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $this->interrupted = true;
        $process = new SymfonyProcess($command->arguments);
        $process->start();
        $deadline = microtime(true) + 30;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (! file_exists($this->movedSource) && is_dir($this->unmovedSource)) {
                $process->stop(0, SIGKILL);

                return new CommandResult(
                    exitCode: $process->getExitCode() ?? 137,
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    durationMs: 0,
                    truncated: false,
                );
            }
        }

        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }

        return new CommandResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: 'Partial prepare interruption was not observed.',
            durationMs: 0,
            truncated: false,
        );
    }
}

final class Orb105InterruptingCleanupSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $source,
        private readonly string $trigger,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->interrupted || ($command->arguments[3] ?? null) !== 'cleanup') {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $this->interrupted = true;
        // Pause after the real unlink so cleanup cannot finish before the observer sends SIGKILL.
        $pauseAfterUnlink = <<<'PYTHON'
            import os, signal
            original_unlink = os.unlink
            def unlink_and_pause(path, *args, **kwargs):
                original_unlink(path, *args, **kwargs)
                if path == %s:
                    os.kill(os.getpid(), signal.SIGSTOP)
            os.unlink = unlink_and_pause
            PYTHON;
        $arguments = $command->arguments;
        $arguments[2] = sprintf($pauseAfterUnlink, json_encode($this->trigger, JSON_THROW_ON_ERROR))
            ."\n".$arguments[2];
        $process = new SymfonyProcess($arguments);
        $process->start();
        $deadline = microtime(true) + 30;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (! file_exists($this->trigger) && is_dir($this->source)) {
                $process->stop(0, SIGKILL);

                return new CommandResult(
                    exitCode: $process->getExitCode() ?? 137,
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    durationMs: 0,
                    truncated: false,
                );
            }
        }

        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }

        return new CommandResult(
            exitCode: 1,
            stdout: $process->getOutput(),
            stderr: 'Cleanup interruption was not observed.',
            durationMs: 0,
            truncated: false,
        );
    }
}

it('closes a relocated Instance environment to other local users after the last verification', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        file_put_contents($fixture['source'].'/.env', "APP_KEY=secret\n");
        chmod($fixture['source'].'/.env', 0o664);
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(fileperms($fixture['destination'].'/.env') & 0o777)->toBe(0o660)
            ->and($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});
