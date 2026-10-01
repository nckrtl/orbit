<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\DevelopmentInstanceCopyIsolationProgram;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceCheckoutCopier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\DeadlineOnCopySshExecutor;

it('reads source eligibility without a copy command', function (): void {
    [$source] = copy_checkout_pair();
    $head = str_repeat('a', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $inspection = $copier->inspect($source, 'feature');

    expect($inspection->head)->toBe($head)
        ->and($ssh->commands)->toHaveCount(2)
        ->and($ssh->commands[0]->arguments[0])->toBe('bash')
        ->and($ssh->commands[1]->arguments[0])->toBe('python3')
        ->and($ssh->commands[1]->arguments[3])->toBe('scan')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_cold')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_dirty')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_layout_invalid')
        ->and($ssh->commands[0]->input)->not->toContain('cp ')
        ->and($ssh->commands[0]->arguments)->toContain(RuntimeHibernation::coldPath(RuntimeHibernation::key($source->id)));
});

it('maps a read-only refusal to its stable code', function (string $code): void {
    [$source] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "refused\n{$code}\n", '', 1, false)]);
    $copier = copy_checkout_copier($ssh);

    expect(fn () => $copier->inspect($source, 'feature'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === $code)
        ->and($ssh->commands)->toHaveCount(1);
})->with([
    'instance.copy_source_cold',
    'instance.copy_source_layout_invalid',
    'instance.copy_source_dirty',
    'instance.copy_branch_diverged',
]);

it('reports a reflink when cp --reflink=always succeeds and creates the branch on the target', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $result = $copier->copy($source, $target, 'feature', $head, 'instance.path_taken');

    expect($result->mode)->toBe('reflink')
        ->and($result->head)->toBe($head)
        ->and($ssh->commands[1]->arguments)->toBe(['sync', '-f', '/srv/orbit/apps'])
        ->and($ssh->commands[2]->arguments[0])->toBe('bash')
        ->and($ssh->commands[2]->input)->toContain('timeout -k 5')
        ->and($ssh->commands[2]->arguments)->toContain(
            '--reflink=always',
            '/srv/orbit/apps/acme/default',
            '/srv/orbit/apps/acme/feature',
        )
        ->and($ssh->commands[2]->arguments[3])->toEndWith('.pid')
        ->and(implode("\n", array_map(static fn ($command) => $command->shellCommand(), $ssh->commands)))->not->toContain('--reflink=auto')
        ->and($ssh->commands[3]->input)->toContain('git -C "$dest" checkout --quiet --force -B "$branch"')
        ->and($ssh->commands[3]->input)->not->toContain('git fetch')
        ->and($ssh->commands[3]->arguments)->toContain('/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/feature')
        ->and($ssh->commands[4]->arguments[0])->toBe('python3')
        ->and($ssh->commands[4]->arguments[3])->toBe('prepare');
});

it('fetches origin in the new checkout and points the task branch at the default tip', function (): void {
    [$source, $target] = copy_checkout_pair();
    $sourceHead = str_repeat('b', 40);
    $tip = str_repeat('e', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$tip}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $result = $copier->copyOntoFetchedTip($source, $target, 'task-9', 'main', $sourceHead, 'instance.path_taken');

    expect($result->mode)->toBe('reflink')
        ->and($result->head)->toBe($tip)
        ->and($result->head)->not->toBe($sourceHead)
        ->and($ssh->commands[3]->arguments)->toContain('task-9', 'main', $sourceHead)
        ->and($ssh->commands[3]->input)->toContain('git_read git -C "$dest" fetch --prune -- origin')
        ->and($ssh->commands[3]->input)->toContain('git -C "$dest" checkout --quiet --force --no-track -B "$branch" "$tip"')
        ->and($ssh->commands[3]->input)->toContain('refs/remotes/origin/$branch')
        ->and($ssh->commands[3]->input)->toContain('git -C "$dest" clean -fd')
        ->and($ssh->commands[3]->input)->toContain('git -C "$dest" status --porcelain --untracked-files=all')
        ->and($ssh->commands[3]->input)->not->toContain('git clean -x')
        ->and($ssh->commands[3]->input)->not->toContain('git -C "$source" fetch')
        ->and($ssh->commands[4]->arguments[3])->toBe('prepare');
});

it('fails the copy when the fetched default branch cannot be read', function (): void {
    [$source, $target] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->copyOntoFetchedTip($source, $target, 'task-9', 'main', str_repeat('b', 40), 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1')
        ->and($ssh->commands[4]->arguments)->toContain('0');
});

it('checks out a pushed task branch and removes untracked files from the copy', function (): void {
    $root = sys_get_temp_dir().'/orbit-fetched-branch-'.bin2hex(random_bytes(4));

    try {
        [$source, $origin] = copy_branch_fixture($root);
        $base = copy_git($source, ['rev-parse', 'HEAD']);
        file_put_contents($source.'/CONTRACT.md', "contract\n");
        copy_git($source, ['add', 'CONTRACT.md']);
        copy_git($source, ['commit', '-m', 'contract']);
        $contract = copy_git($source, ['rev-parse', 'HEAD']);
        copy_git($source, ['push', 'origin', 'HEAD:task-9']);
        copy_git($source, ['reset', '--hard', $base]);
        file_put_contents($source.'/notes.md', "scratch\n");
        mkdir($source.'/scratch');
        file_put_contents($source.'/scratch/note.txt', "scratch\n");
        $dest = $root.'/dest';
        (new Process(['cp', '-a', '--', $source, $dest]))->mustRun();
        $originUrl = copy_git($dest, ['config', '--get', 'remote.origin.url']);

        $result = run_fetched_branch_script($source, $dest, 'task-9', $base, $originUrl, 'main');

        expect($result->isSuccessful() ? trim($result->getOutput()) : $result->getErrorOutput())->toBe("ready\n{$contract}")
            ->and(copy_git($dest, ['rev-parse', 'HEAD']))->toBe($contract)
            ->and(copy_git($dest, ['symbolic-ref', '--short', 'HEAD']))->toBe('task-9')
            ->and(copy_git($source, ['rev-parse', 'HEAD']))->toBe($base)
            ->and(is_file($dest.'/CONTRACT.md'))->toBeTrue()
            ->and(is_file($dest.'/notes.md'))->toBeFalse()
            ->and(is_dir($dest.'/scratch'))->toBeFalse()
            ->and(is_file($dest.'/vendor/keep'))->toBeTrue()
            ->and(is_file($source.'/notes.md'))->toBeTrue()
            ->and(copy_git($dest, ['status', '--porcelain', '--untracked-files=all']))->toBe('');
        $upstream = new Process(['git', '-C', $dest, 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}']);
        $upstream->run();
        expect($upstream->isSuccessful())->toBeFalse()
            ->and($origin)->not->toBe('');
    } finally {
        if (is_dir($root)) {
            (new Process(['rm', '-rf', '--', $root]))->mustRun();
        }
    }
});

it('points the task branch at the fetched default tip when the contract ref is absent', function (): void {
    $root = sys_get_temp_dir().'/orbit-fetched-default-'.bin2hex(random_bytes(4));

    try {
        [$source] = copy_branch_fixture($root);
        $base = copy_git($source, ['rev-parse', 'HEAD']);
        file_put_contents($source.'/NEWER.md', "newer\n");
        copy_git($source, ['add', 'NEWER.md']);
        copy_git($source, ['commit', '-m', 'newer']);
        $tip = copy_git($source, ['rev-parse', 'HEAD']);
        copy_git($source, ['push', 'origin', 'main']);
        copy_git($source, ['reset', '--hard', $base]);
        file_put_contents($source.'/notes.md', "scratch\n");
        $dest = $root.'/dest';
        (new Process(['cp', '-a', '--', $source, $dest]))->mustRun();
        $originUrl = copy_git($dest, ['config', '--get', 'remote.origin.url']);

        $result = run_fetched_branch_script($source, $dest, 'task-9', $base, $originUrl, 'main');

        expect($result->isSuccessful() ? trim($result->getOutput()) : $result->getErrorOutput())->toBe("ready\n{$tip}")
            ->and(copy_git($dest, ['rev-parse', 'HEAD']))->toBe($tip)
            ->and($tip)->not->toBe($base)
            ->and(copy_git($source, ['rev-parse', 'HEAD']))->toBe($base)
            ->and(is_file($dest.'/notes.md'))->toBeFalse()
            ->and(is_file($dest.'/vendor/keep'))->toBeTrue();
    } finally {
        if (is_dir($root)) {
            (new Process(['rm', '-rf', '--', $root]))->mustRun();
        }
    }
});

it('treats a second discard as done only when the owned tree is already gone', function (): void {
    $root = sys_get_temp_dir().'/orbit-discard-'.bin2hex(random_bytes(4));
    $apps = $root.'/apps';
    $dest = $apps.'/acme/task-9';
    $marker = $apps.'/.orbit/copies/instance-9';

    try {
        mkdir($apps.'/.orbit/copies', 0777, true);
        mkdir($dest, 0777, true);
        file_put_contents($dest.'/notes.md', "partial\n");
        file_put_contents($marker, $dest."\n");
        $script = (new ReflectionMethod(RemoteDevelopmentInstanceCheckoutCopier::class, 'discardScript'))->invoke(null);

        $first = new Process(['bash', '-seu', '--', $marker, $dest, $apps, '0']);
        $first->setInput($script);
        $first->mustRun();

        expect(is_dir($dest))->toBeFalse()
            ->and(is_file($marker))->toBeFalse();

        $second = new Process(['bash', '-seu', '--', $marker, $dest, $apps, '0']);
        $second->setInput($script);
        $second->mustRun();

        mkdir($dest, 0777, true);
        $blocked = new Process(['bash', '-seu', '--', $marker, $dest, $apps, '0']);
        $blocked->setInput($script);
        $blocked->run();

        expect($blocked->isSuccessful())->toBeFalse()
            ->and(is_dir($dest))->toBeTrue();
    } finally {
        if (is_dir($root)) {
            (new Process(['rm', '-rf', '--', $root]))->mustRun();
        }
    }
});

it('falls back to a plain copy only for a reflink errno and reports full', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', "cp: failed to clone 'file': Operation not supported\n", 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $result = $copier->copy($source, $target, 'feature', $head, 'instance.path_taken');

    expect($result->mode)->toBe('full')
        ->and($ssh->commands[4]->arguments[0])->toBe('bash')
        ->and($ssh->commands[4]->input)->toContain('timeout -k 5')
        ->and($ssh->commands[4]->arguments)->toContain(
            '/srv/orbit/apps/acme/default',
            '/srv/orbit/apps/acme/feature',
        )
        ->and($ssh->commands[4]->arguments)->not->toContain('--reflink=always')
        ->and($ssh->commands[3]->input)->toContain('rm -rf -- "$dest"');
});

it('continues the copy when sync fails', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(1, '', 'sync failed', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ]);

    expect(copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken')->mode)
        ->toBe('reflink')
        ->and($ssh->commands[2]->arguments)->toContain('--reflink=always');
});

it('retries one missing reset path and does not treat another missing path as a fallback', function (string $stderr, bool $retried): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $results = [
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', $stderr, 1, false),
    ];

    if ($retried) {
        $results[] = new CommandResult(0, '', '', 1, false);
        $results[] = new CommandResult(0, '', '', 1, false);
        $results[] = new CommandResult(0, "ready\n{$head}\n", '', 1, false);
        $results[] = new CommandResult(0, "ready\n", '', 1, false);
    } else {
        $results[] = new CommandResult(0, '', '', 1, false);
    }

    $ssh = new AppDevFakeSshExecutor($results);
    $copy = fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken');

    if ($retried) {
        expect($copy()->mode)->toBe('reflink')
            ->and($ssh->commands[4]->arguments)->toContain('--reflink=always');

        return;
    }

    expect($copy)->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1')
        ->and(array_values(array_filter(
            $ssh->commands,
            static fn ($command): bool => in_array('--reflink=always', $command->arguments, true),
        )))->toHaveCount(1)
        ->and(array_values(array_filter(
            $ssh->commands,
            static fn ($command): bool => in_array('0', $command->arguments, true),
        )))->not->toBeEmpty();
})->with([
    'reset path' => ["cp: cannot stat '/srv/orbit/apps/acme/default/storage/logs/laravel.log': No such file or directory\n", true],
    'tracked path' => ["cp: cannot stat '/srv/orbit/apps/acme/default/composer.json': No such file or directory\n", false],
]);

it('removes a partial tree when reflink fails for another reason', function (): void {
    [$source, $target] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', "cp: failed: Permission denied\n", 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', str_repeat('b', 40), 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1')
        ->and($ssh->commands[3]->arguments)->toContain('0');
});

it('discards the owned tree when cp exceeds the deadline and keeps that error', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new DeadlineOnCopySshExecutor($head);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'command.deadline_exceeded'
            && $exception->status === 504
            && ($exception->details['copy_started'] ?? '') === '1');

    $discard = $ssh->commands[array_key_last($ssh->commands)];

    $copy = array_values(array_filter(
        $ssh->commands,
        static fn ($command): bool => in_array('--reflink=always', $command->arguments, true),
    ));

    expect($discard->input)->toContain('keep_marker')
        ->and($discard->input)->toContain('kill -TERM')
        ->and($discard->arguments)->toContain('0', '/srv/orbit/apps/acme/feature')
        ->and($copy)->toHaveCount(1)
        ->and($copy[0]->input)->toContain('timeout -k 5')
        ->and($copy[0]->arguments[3])->toEndWith('.pid');
});

it('refuses an occupied destination before copying', function (): void {
    [$source, $target] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "occupied\n", '', 1, false)]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', str_repeat('b', 40), 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.path_taken' && $exception->details === [])
        ->and($ssh->commands)->toHaveCount(1);
});

it('removes the partial target when the source HEAD moves', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "changed\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_source_changed');
});

it('refuses a SQLite symlink before it copies', function (): void {
    [$source] = copy_checkout_pair();
    $head = str_repeat('a', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "unsafe\n", '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->inspect($source, 'feature'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_source_unsafe' && $exception->details === [])
        ->and($ssh->commands)->toHaveCount(2)
        ->and(implode(' ', $ssh->commands[1]->arguments))->not->toContain('cp');
});

it('snapshots SQLite from the live source, rewrites names, and resets runtime files', function (): void {
    $root = sys_get_temp_dir().'/orbit-copy-'.bin2hex(random_bytes(4));
    $source = $root.'/source';
    $dest = $root.'/dest';
    $outside = $root.'/outside';

    try {
        copy_isolation_fixture($source, $outside);
        $copied = new Process(['cp', '-a', $source, $dest]);
        $copied->mustRun();
        copy_isolation_diverge($source, $dest, $outside);

        $process = new Process([
            'python3', '-c', DevelopmentInstanceCopyIsolationProgram::script(),
            'prepare', $source, $dest, 'default.acme.test', 'feature.acme.test',
        ]);
        $process->mustRun();

        expect(trim($process->getOutput()))->toBe('ready')
            ->and(copy_isolation_names($dest.'/database/database.sqlite'))->toBe(['kept', 'live'])
            ->and(copy_isolation_names($source.'/database/database.sqlite'))->toBe(['kept', 'live'])
            ->and(is_file($dest.'/database/database.sqlite-wal'))->toBeFalse()
            ->and(is_file($dest.'/database/database.sqlite-shm'))->toBeFalse()
            ->and(copy_isolation_names($dest.'/vendor/package/cache.sqlite'))->toBe(['vendor-old'])
            ->and(copy_isolation_names($source.'/vendor/package/cache.sqlite'))->toBe(['vendor-new', 'vendor-old'])
            ->and(is_file($outside.'/hot-target'))->toBeTrue()
            ->and(is_link($dest.'/public/hot') || is_file($dest.'/public/hot'))->toBeFalse()
            ->and(is_file($outside.'/logs/laravel.log'))->toBeTrue()
            ->and(is_link($dest.'/storage/framework/sessions'))->toBeFalse()
            ->and(readlink($dest.'/public/storage'))->toBe($dest.'/storage/app/public')
            ->and(readlink($dest.'/public/relative'))->toBe('../storage/app/public')
            ->and(readlink($dest.'/public/neighbor'))->toBe($source.'-two/storage')
            ->and(is_file($dest.'/storage/logs/laravel.log'))->toBeFalse()
            ->and(is_file($dest.'/storage/logs/.gitignore'))->toBeTrue()
            ->and(is_file($dest.'/storage/framework/cache/data.php'))->toBeFalse()
            ->and(is_file($dest.'/storage/framework/cache/.gitignore'))->toBeTrue()
            ->and(is_file($dest.'/storage/framework/cache/data/.gitignore'))->toBeTrue()
            ->and(is_file($dest.'/storage/framework/cache/data/payload.php'))->toBeFalse()
            ->and(is_file($dest.'/storage/framework/views/home.php'))->toBeFalse()
            ->and(is_dir($dest.'/node_modules/.vite'))->toBeFalse()
            ->and(is_dir($dest.'/node_modules/.cache'))->toBeFalse()
            ->and(is_file($dest.'/node_modules/left/index.js'))->toBeTrue()
            ->and(file_get_contents($dest.'/.env'))->toBe(implode("\n", [
                'DB_DATABASE='.$dest.'/database/database.sqlite',
                'APP_URL=https://feature.acme.test',
                'AGENTATION_URL=https://feature.acme.test/__orbit/agentation',
                'NESTED=prefix '.$dest.'/storage',
                'EXACT='.$dest,
                'QUOTED="'.$dest.'"',
                'UNRELATED='.$source.'-two/file',
                'DOTFILE='.$source.'.sqlite',
                'HOST_PORT=feature.acme.test:443',
                'PATH_URL=https://feature.acme.test/path',
                'NOT_HOST=notdefault.acme.test',
                'SUB_HOST=api.default.acme.test',
                'DOT_HOST=default.acme.test.other',
                '',
            ]))
            ->and(file_get_contents($dest.'/bootstrap/cache/config.php'))->toContain("'database' => '{$dest}/database/database.sqlite'")
            ->and(file_get_contents($dest.'/bootstrap/cache/config.php'))->toContain('https://feature.acme.test')
            ->and(file_get_contents($dest.'/bootstrap/cache/config.php'))->not->toContain('https://default.acme.test')
            ->and(file_get_contents($dest.'/bootstrap/cache/config.php'))->toContain($source.'-two')
            ->and(file_get_contents($source.'/.env'))->toBe(copy_isolation_env($source, 'default.acme.test'));
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

it('does not reset or rewrite through a parent symlink for a client or workspace copy', function (): void {
    $root = sys_get_temp_dir().'/orbit-copy-parent-link-'.bin2hex(random_bytes(4));
    $source = $root.'/source';
    $dest = $root.'/dest';
    $outside = $root.'/outside';

    try {
        copy_isolation_fixture($source, $outside);
        $copied = new Process(['cp', '-a', $source, $dest]);
        $copied->mustRun();
        $cache = $source.'/storage/framework/cache/data/payload.php';
        $session = $outside.'/logs/laravel.log';
        $view = $source.'/storage/framework/views/home.php';
        $config = $source.'/bootstrap/cache/config.php';
        $hot = $outside.'/hot-target';
        $before = [
            $cache => (string) file_get_contents($cache),
            $session => (string) file_get_contents($session),
            $view => (string) file_get_contents($view),
            $config => (string) file_get_contents($config),
            $hot => (string) file_get_contents($hot),
        ];
        new Process(['rm', '-rf', $dest.'/storage/framework', $dest.'/bootstrap', $dest.'/public'])->mustRun();
        if (! is_dir($dest.'/storage')) {
            mkdir($dest.'/storage', 0777, true);
        }
        symlink($source.'/storage/framework', $dest.'/storage/framework');
        symlink($source.'/bootstrap', $dest.'/bootstrap');
        symlink($source.'/public', $dest.'/public');

        $process = new Process([
            'python3', '-c', DevelopmentInstanceCopyIsolationProgram::script(),
            'prepare', $source, $dest, 'default.acme.test', 'feature.acme.test',
        ]);
        $process->mustRun();

        expect(trim($process->getOutput()))->toBe('ready');

        foreach ($before as $path => $contents) {
            expect(is_file($path))->toBeTrue()
                ->and(file_get_contents($path))->toBe($contents);
        }

        expect(readlink($dest.'/storage/framework'))->toBe($dest.'/storage/framework')
            ->and(readlink($dest.'/bootstrap'))->toBe($dest.'/bootstrap')
            ->and(readlink($dest.'/public'))->toBe($dest.'/public')
            ->and(is_link($dest.'/storage/logs') || is_dir($dest.'/storage/logs'))->toBeTrue();
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

it('runs the same isolation program from a client copy and a workspace copy', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $script = DevelopmentInstanceCopyIsolationProgram::script();
    $results = [
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
        new CommandResult(0, "ready\n", '', 1, false),
    ];

    foreach (['copy', 'workspace'] as $path) {
        $ssh = new AppDevFakeSshExecutor($results);
        $copier = copy_checkout_copier($ssh);

        if ($path === 'copy') {
            $copier->copy($source, $target, 'feature', $head, 'instance.path_taken');
        } else {
            $copier->copyOntoFetchedTip($source, $target, 'task-1', 'main', $head, 'instance.path_taken');
        }

        $prepare = $ssh->commands[array_key_last($ssh->commands)];

        expect($prepare->arguments[0])->toBe('python3')
            ->and($prepare->arguments[2])->toBe($script)
            ->and($prepare->arguments[3])->toBe('prepare')
            ->and($script)->toContain('def place(');
    }
});

it('leaves the target untouched when a SQLite path is a symlink', function (): void {
    $root = sys_get_temp_dir().'/orbit-copy-link-'.bin2hex(random_bytes(4));
    $source = $root.'/source';
    $dest = $root.'/dest';

    try {
        mkdir($source.'/database', 0777, true);
        mkdir($dest.'/public', 0777, true);
        $database = new PDO('sqlite:'.$source.'/database/database.sqlite');
        $database->exec('create table rows (name text)');
        $database->exec("insert into rows values ('kept')");
        unset($database);
        symlink($source.'/database/database.sqlite', $source.'/database/linked.sqlite');
        file_put_contents($dest.'/public/hot', 'stay');

        $process = new Process([
            'python3', '-c', DevelopmentInstanceCopyIsolationProgram::script(),
            'prepare', $source, $dest, '', '',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and(trim($process->getOutput()))->toBe('unsafe')
            ->and(file_get_contents($dest.'/public/hot'))->toBe('stay');
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

it('fails a torn SQLite header without treating it as a reflink fallback', function (): void {
    $root = sys_get_temp_dir().'/orbit-copy-bad-'.bin2hex(random_bytes(4));
    $source = $root.'/source';
    $dest = $root.'/dest';

    try {
        mkdir($source.'/database', 0777, true);
        mkdir($dest.'/database', 0777, true);
        file_put_contents($source.'/database/database.sqlite', "SQLite format 3\0not-a-database");
        file_put_contents($dest.'/database/database.sqlite', "SQLite format 3\0not-a-database");

        $process = new Process([
            'python3', '-c', DevelopmentInstanceCopyIsolationProgram::script(),
            'prepare', $source, $dest, '', '',
        ]);
        $process->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('failed');
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

/** @return array{Instance, Instance} */
function copy_checkout_pair(): array
{
    $node = Node::query()->create([
        'name' => 'copy-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.60',
        'wireguard_ip' => '10.44.0.60',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
    $target = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/acme/feature',
        'branch_override' => 'feature',
        'status' => InstanceState::Reserved,
    ]);

    return [$source->refresh(), $target->refresh()];
}

function copy_checkout_copier(SshExecutor $ssh): RemoteDevelopmentInstanceCheckoutCopier
{
    return new RemoteDevelopmentInstanceCheckoutCopier(
        ssh: $ssh,
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA orbit';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        },
        storageRoots: app(StorageRootResolver::class),
        nodeSettings: app(NodeSettingsNormalizer::class),
        access: app(RepositoryReadAccess::class),
    );
}

function copy_isolation_fixture(string $source, string $outside): void
{
    foreach ([
        $source.'/database',
        $source.'/public',
        $source.'/storage/app/public',
        $source.'/storage/logs',
        $source.'/storage/framework/cache/data',
        $source.'/storage/framework/views',
        $source.'/bootstrap/cache',
        $source.'/node_modules/left',
        $source.'/node_modules/.vite',
        $source.'/node_modules/.cache',
        $source.'/vendor/package',
        $outside.'/logs',
    ] as $directory) {
        mkdir($directory, 0777, true);
    }

    $database = new PDO('sqlite:'.$source.'/database/database.sqlite');
    $database->exec('create table rows (name text)');
    $database->exec("insert into rows (name) values ('kept')");
    unset($database);
    $vendor = new PDO('sqlite:'.$source.'/vendor/package/cache.sqlite');
    $vendor->exec('create table rows (name text)');
    $vendor->exec("insert into rows (name) values ('vendor-old')");
    unset($vendor);
    file_put_contents($source.'/.env', copy_isolation_env($source, 'default.acme.test'));
    file_put_contents($source.'/bootstrap/cache/config.php', <<<PHP
        <?php return ['database' => '{$source}/database/database.sqlite', 'url' => 'https://default.acme.test', 'other' => '{$source}-two/file'];
        PHP);
    file_put_contents($source.'/storage/logs/laravel.log', 'log');
    file_put_contents($source.'/storage/logs/.gitignore', "*\n!.gitignore\n");
    file_put_contents($source.'/storage/framework/cache/data.php', 'cache');
    file_put_contents($source.'/storage/framework/cache/.gitignore', "*\n!.gitignore\n");
    file_put_contents($source.'/storage/framework/cache/data/.gitignore', "*\n!.gitignore\n");
    file_put_contents($source.'/storage/framework/cache/data/payload.php', 'payload');
    file_put_contents($source.'/storage/framework/views/home.php', 'view');
    file_put_contents($source.'/node_modules/left/index.js', 'keep');
    file_put_contents($source.'/node_modules/.vite/deps', 'vite');
    file_put_contents($source.'/node_modules/.cache/data', 'cache');
    file_put_contents($outside.'/hot-target', 'hot');
    file_put_contents($outside.'/logs/laravel.log', 'outside');
    symlink($outside.'/hot-target', $source.'/public/hot');
    symlink($outside.'/logs', $source.'/storage/framework/sessions');
    symlink($source.'/storage/app/public', $source.'/public/storage');
    symlink('../storage/app/public', $source.'/public/relative');
    symlink($source.'-two/storage', $source.'/public/neighbor');
}

function copy_isolation_diverge(string $source, string $dest, string $outside): void
{
    $database = new PDO('sqlite:'.$dest.'/database/database.sqlite');
    $database->exec('delete from rows');
    $database->exec("insert into rows (name) values ('stale')");
    unset($database);
    file_put_contents($dest.'/database/database.sqlite-wal', 'torn');
    file_put_contents($dest.'/database/database.sqlite-shm', 'torn');
    $live = new PDO('sqlite:'.$source.'/database/database.sqlite');
    $live->exec("insert into rows (name) values ('live')");
    unset($live);
    $vendor = new PDO('sqlite:'.$source.'/vendor/package/cache.sqlite');
    $vendor->exec("insert into rows (name) values ('vendor-new')");
    unset($vendor);
    expect(is_file($outside.'/hot-target'))->toBeTrue();
}

/** @return list<string> */
function copy_isolation_names(string $path): array
{
    $database = new PDO('sqlite:'.$path);
    $names = $database->query('select name from rows order by name');

    if ($names === false) {
        throw new RuntimeException('The SQLite rows could not be read.');
    }

    unset($database);

    return array_values(array_map(strval(...), $names->fetchAll(PDO::FETCH_COLUMN)));
}

function copy_isolation_env(string $checkout, string $domain): string
{
    return implode("\n", [
        'DB_DATABASE='.$checkout.'/database/database.sqlite',
        'APP_URL=https://'.$domain,
        'AGENTATION_URL=https://'.$domain.'/__orbit/agentation',
        'NESTED=prefix '.$checkout.'/storage',
        'EXACT='.$checkout,
        'QUOTED="'.$checkout.'"',
        'UNRELATED='.$checkout.'-two/file',
        'DOTFILE='.$checkout.'.sqlite',
        'HOST_PORT='.$domain.':443',
        'PATH_URL=https://'.$domain.'/path',
        'NOT_HOST=not'.$domain,
        'SUB_HOST=api.'.$domain,
        'DOT_HOST='.$domain.'.other',
        '',
    ]);
}

/** @return array{0: string, 1: string} */
function copy_branch_fixture(string $root): array
{
    $origin = $root.'/origin.git';
    $source = $root.'/source';
    mkdir($origin, 0777, true);
    mkdir($source, 0777, true);
    copy_git($origin, ['init', '--bare', '-b', 'main']);
    copy_git($source, ['init', '-b', 'main']);
    file_put_contents($source.'/README.md', "readme\n");
    file_put_contents($source.'/.gitignore', "vendor\n");
    mkdir($source.'/vendor');
    file_put_contents($source.'/vendor/keep', "kept\n");
    copy_git($source, ['add', 'README.md', '.gitignore']);
    copy_git($source, ['commit', '-m', 'base']);
    copy_git($source, ['remote', 'add', 'origin', $origin]);
    copy_git($source, ['push', 'origin', 'main']);

    return [$source, $origin];
}

/** @param  list<string>  $arguments */
function copy_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd, copy_git_env());
    $process->mustRun();

    return trim($process->getOutput());
}

function run_fetched_branch_script(
    string $source,
    string $dest,
    string $branch,
    string $expected,
    string $origin,
    string $defaultBranch,
): Process {
    $script = "git_read() ( exec \"\$@\" )\n".(new ReflectionMethod(RemoteDevelopmentInstanceCheckoutCopier::class, 'fetchedBranchScript'))->invoke(null);
    $process = new Process(
        ['bash', '-seu', '--', $source, $dest, $branch, $expected, $origin, $defaultBranch],
        null,
        copy_git_env(),
    );
    $process->setInput($script);
    $process->run();

    return $process;
}

/** @return array<string, string> */
function copy_git_env(): array
{
    return [
        'GIT_AUTHOR_NAME' => 'Orbit',
        'GIT_AUTHOR_EMAIL' => 'orbit@example.test',
        'GIT_COMMITTER_NAME' => 'Orbit',
        'GIT_COMMITTER_EMAIL' => 'orbit@example.test',
    ];
}
