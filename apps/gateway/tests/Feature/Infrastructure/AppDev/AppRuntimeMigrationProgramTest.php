<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\AppRuntimeMigrationProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('snapshots per-app route runtime files and queued annotator data and restores them on prepublication rollback', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        $before = file_get_contents($root.'/units/active.service');
        $queued = file_get_contents($root.'/stores/instance-1/queue.json');
        $inode = fileinode($root.'/stores/instance-1/queue.json');
        $mode = fileperms($root.'/units/active.service') & 0o777;
        $observation = json_decode($run('prepare')->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect(json_decode($run('prepare')->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($observation);
        expect($observation['running'])->toBe(['active.service' => true, 'failed.service' => false]);
        $run('activate');
        expect(file_get_contents($root.'/stores/instance-1-web/queue.json'))->toBe($queued)
            ->and(fileinode($root.'/stores/instance-1-web/queue.json'))->toBe($inode)
            ->and(file_get_contents($root.'/units/active.service'))->toContain('PORT=4747')
            ->and(file_get_contents($root.'/calls'))->not->toContain('start failed.service');
        // A lost activation response rechecks the receipt, rather than restarting a second time.
        $calls = file_get_contents($root.'/calls');
        $run('activate');
        expect(file_get_contents($root.'/calls'))->toBe($calls);
        file_put_contents($root.'/caddy/Caddyfile', '# candidate Caddy projection');
        $run('rollback');
        expect(file_get_contents($root.'/units/active.service'))->toBe($before)
            ->and(fileperms($root.'/units/active.service') & 0o777)->toBe($mode)
            ->and(file_get_contents($root.'/caddy/Caddyfile'))->toBe('# original Caddy projection')
            ->and(file_get_contents($root.'/stores/instance-1/queue.json'))->toBe($queued)
            ->and(is_dir($root.'/stores/instance-1-web'))->toBeFalse()
            ->and(file_get_contents($root.'/calls'))->not->toContain('start failed.service');
        $run('prepare');
        $run('activate');
        $run('cleanup');
        expect(file_get_contents($root.'/stores/instance-1-web/queue.json'))->toBe($queued)
            ->and(is_dir($root.'/journals/'.$payload['id']))->toBeFalse();
        $run('cleanup');
    });
});

it('retains the exact legacy Vite file until every unit reference is gone and retries forward cleanup', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        $legacy = $root.'/units/legacy.env';
        file_put_contents($legacy, "# Orbit Instance 1\nORBIT_DEV_SERVER_PORT=5173\n");
        $original = file_get_contents($legacy);
        $extra = ['retired_files' => [['path' => $legacy, 'tag' => '# Orbit Instance 1']]];
        $run('prepare', true, $extra);
        $run('activate', true, $extra);
        file_put_contents($root.'/units/pending.service', "EnvironmentFile={$legacy}\n");
        expect($run('cleanup', false, $extra)->getExitCode())->toBe(43)
            ->and(file_get_contents($legacy))->toBe($original)
            ->and(is_dir($root.'/journals/'.$payload['id']))->toBeTrue();
        unlink($root.'/units/pending.service');
        // Ownership drift must not authorize deleting an unrelated runtime file.
        file_put_contents($legacy, "# Orbit Instance 1\nforeign update\n");
        expect($run('cleanup', false, $extra)->isSuccessful())->toBeFalse();
        file_put_contents($legacy, $original);
        $run('cleanup', true, $extra);
        expect(file_exists($legacy))->toBeFalse();
        $run('cleanup', true, $extra); // Lost cleanup response is safe.
    });
});

it('creates an owned missing store and preserves annotations arriving before rollback and retry', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        unlink($root.'/stores/instance-1/queue.json');
        rmdir($root.'/stores/instance-1');
        $run('prepare');
        $run('activate');
        expect(fileperms($root.'/stores/instance-1-web') & 0o777)->toBe(0o700)
            ->and(fileowner($root.'/stores/instance-1-web'))->toBe(posix_geteuid());
        file_put_contents($root.'/stores/instance-1-web/queue.json', 'arrived during activation');
        $inode = fileinode($root.'/stores/instance-1-web/queue.json');
        $run('rollback');
        expect(file_get_contents($root.'/stores/instance-1/queue.json'))->toBe('arrived during activation');
        $run('prepare');
        $run('activate');
        $run('cleanup');
        expect(file_get_contents($root.'/stores/instance-1-web/queue.json'))->toBe('arrived during activation')
            ->and(fileinode($root.'/stores/instance-1-web/queue.json'))->toBe($inode);
    });
});

it('refuses an occupied sibling store before any per-app route migration runtime is stopped or published', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        mkdir($root.'/stores/instance-1-web');
        file_put_contents($root.'/stores/instance-1-web/queue.json', 'foreign sibling data');
        expect($run('prepare', false)->isSuccessful())->toBeFalse()
            ->and(file_get_contents($root.'/stores/instance-1/queue.json'))->toBe('{"queued":"web"}')
            ->and(file_get_contents($root.'/stores/instance-1-web/queue.json'))->toBe('foreign sibling data')
            ->and(file_get_contents($root.'/calls'))->not->toContain('stop ');
        $run('rollback');
        expect(file_get_contents($root.'/stores/instance-1-web/queue.json'))->toBe('foreign sibling data');
    });
});

it('refuses another app or an unqualified file at a qualified per-app route Vite migration path', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        $path = $root.'/vite-web.env';
        $tag = '# Orbit Instance 1'."\n# Orbit App web";
        $extra = ['files' => [...$payload['files'], ['path' => $path, 'tag' => $tag, 'contents' => base64_encode($tag."\nORBIT_DEV_SERVER_PORT=4747\n")]]];
        foreach (["# Orbit Instance 1\n# Orbit App docs\n", "# Orbit Instance 1\n", "# Orbit Instance 1\n# Orbit App web\n# Orbit App docs\n"] as $foreign) {
            file_put_contents($path, $foreign);
            expect($run('prepare', false, $extra)->getExitCode())->toBe(41)->and(file_get_contents($path))->toBe($foreign)
                ->and(file_exists($root.'/stores/instance-1/queue.json'))->toBeTrue();
        }
        file_put_contents($path, $tag."\nORBIT_DEV_SERVER_PORT=5173\n");
        $run('prepare', true, $extra);
        $run('activate', true, $extra);
        expect(file_get_contents($path))->toBe($tag."\nORBIT_DEV_SERVER_PORT=4747\n");
        $run('rollback', true, $extra);
        expect(file_get_contents($path))->toBe($tag."\nORBIT_DEV_SERVER_PORT=5173\n");
    });
});

it('refuses symlinked queued data and unowned unit markers in a per-app route migration snapshot', function (): void {
    runtime_program_fixture(function (string $root, array $payload, Closure $run): void {
        symlink($root.'/caddy/Caddyfile', $root.'/stores/instance-1/foreign-link');
        expect($run('prepare', false)->isSuccessful())->toBeFalse()->and(file_get_contents($root.'/calls'))->not->toContain('stop ');
        unlink($root.'/stores/instance-1/foreign-link');
        file_put_contents($root.'/units/active.service', "X-Orbit-Process-ID=999\n");
        expect($run('prepare', false)->isSuccessful())->toBeFalse()->and(file_get_contents($root.'/calls'))->not->toContain('stop ');
    });
});

/** This executes the real filesystem program on task-owned scratch paths with a simulated
 * systemd command boundary. It is not live systemd, Caddy, a socket check, or Incus reproduction.
 */
function runtime_program_fixture(Closure $test): void
{
    $root = sys_get_temp_dir().'/orbit-runtime-journal-'.Str::uuid();
    $files = new Filesystem;
    foreach (['bin', 'units', 'caddy', 'stores/instance-1', 'journals'] as $path) {
        $files->ensureDirectoryExists($root.'/'.$path);
    }
    chmod($root.'/journals', 0700);
    file_put_contents($root.'/caddy/Caddyfile', '# original Caddy projection');
    file_put_contents($root.'/stores/instance-1/queue.json', '{"queued":"web"}');
    file_put_contents($root.'/states', json_encode(['active.service' => true, 'failed.service' => false, 'caddy.service' => true], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/calls', '');
    file_put_contents($root.'/bin/systemctl', <<<'PYTHON'
        #!/usr/bin/env python3
        import json, os, pathlib, sys
        root = pathlib.Path(os.environ['ORBIT_MIGRATION_TEST_ROOT'])
        states = json.loads((root / 'states').read_text())
        action, unit = sys.argv[1], sys.argv[-1]
        if action == 'is-active': raise SystemExit(0 if states.get(unit, False) else 3)
        with (root / 'calls').open('a') as handle: handle.write(action + ' ' + unit + '\n')
        if action in ('start', 'stop'): states[unit] = action == 'start'
        (root / 'states').write_text(json.dumps(states))
        PYTHON);
    foreach (['systemd-analyze', 'caddy'] as $command) {
        file_put_contents($root.'/bin/'.$command, "#!/bin/sh\nexit 0\n");
    }
    foreach (['systemctl', 'systemd-analyze', 'caddy'] as $command) {
        chmod($root.'/bin/'.$command, 0755);
    }
    $desired = [];
    foreach (['active', 'failed'] as $index => $name) {
        $tag = 'X-Orbit-Process-ID='.($index + 1);
        file_put_contents($root.'/units/'.$name.'.service', "{$tag}\nPORT=5173\n");
        $desired[] = ['path' => $root.'/units/'.$name.'.service', 'tag' => $tag, 'contents' => base64_encode("{$tag}\nPORT=4747\n")];
    }
    $payload = ['id' => (string) Str::uuid(), 'plan_digest' => str_repeat('a', 64), 'files' => $desired, 'units' => ['active.service', 'failed.service'], 'stores' => [['old' => $root.'/stores/instance-1', 'new' => $root.'/stores/instance-1-web']], 'user' => posix_getpwuid(posix_geteuid())['name']];
    // Only fixed deployment roots change; ownership, journal, rollback and state logic is unchanged.
    $script = str_replace(['/var/lib/orbit/runtime-migrations', '/etc/caddy/Caddyfile', '/etc/systemd/system', '/run/systemd/system', '/usr/lib/systemd/system'], [$root.'/journals', $root.'/caddy/Caddyfile', $root.'/units', $root.'/run-units', $root.'/vendor-units'], AppRuntimeMigrationProgram::script());
    $run = static function (string $action, bool $mustSucceed = true, array $extra = []) use ($script, $payload, $root): Process {
        $process = new Process(['python3', '-c', $script], env: ['PATH' => $root.'/bin:'.getenv('PATH'), 'ORBIT_MIGRATION_TEST_ROOT' => $root]);
        $process->setInput(json_encode([...$payload, ...$extra, 'action' => $action], JSON_THROW_ON_ERROR));
        $process->run();
        if ($mustSucceed) {
            expect($process->getErrorOutput())->toBe('')->and($process->getExitCode())->toBe(0);
        }

        return $process;
    };
    try {
        $test($root, $payload, $run);
    } finally {
        $files->deleteDirectory($root);
    }
}
