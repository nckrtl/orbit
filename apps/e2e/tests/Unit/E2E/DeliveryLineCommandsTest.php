<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $arguments
 * @return array{exit: int, stdout: string, stderr: string, json: ?array<string, mixed>}
 */
function deliveryLineRun(string $script, array $arguments, ?string $cwd = null): array
{
    $repository = dirname(__DIR__, 5);
    $process = new Process([$repository.'/bin/'.$script, ...$arguments], $cwd ?? $repository);
    $process->setTimeout(60);
    $process->run();
    $stdout = $process->getOutput();
    $decoded = json_decode($stdout, true);
    $json = null;
    if (is_array($decoded)) {
        $json = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $json[$key] = $value;
            }
        }
    }

    return [
        'exit' => $process->getExitCode() ?? 1,
        'stdout' => $stdout,
        'stderr' => $process->getErrorOutput(),
        'json' => $json,
    ];
}

/** @return array{root: string, main: string} */
function deliveryLineRepo(): array
{
    $root = temporaryPath('orbit-delivery-line-', 6);
    mkdir($root, 0o700, true);
    file_put_contents($root.'/repro.sh', "#!/bin/sh\nexit 0\n");
    chmod($root.'/repro.sh', 0o700);
    $git = static function (string ...$arguments) use ($root): string {
        $process = new Process(['git', '-C', $root, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $git('init', '-b', 'main');
    $git('config', 'user.name', 'Orbit');
    $git('config', 'user.email', 'orbit@example.test');
    $git('add', 'repro.sh');
    $git('commit', '-m', 'main');
    $main = $git('rev-parse', 'HEAD');

    return ['root' => $root, 'main' => $main];
}

function deliveryLineFixture(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/delivery-line/'.$name;
}

/** @return array{root: string, stale: string, current: string} */
function deliveryLineStaleOriginRepo(): array
{
    $remote = temporaryPath('orbit-delivery-remote-', 6);
    mkdir($remote, 0o700, true);
    file_put_contents($remote.'/repro.sh', "#!/bin/sh\nexit 1\n");
    chmod($remote.'/repro.sh', 0o700);
    $git = static function (string $root, string ...$arguments): string {
        $process = new Process(['git', '-C', $root, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $git($remote, 'init', '-b', 'main');
    $git($remote, 'config', 'user.name', 'Orbit');
    $git($remote, 'config', 'user.email', 'orbit@example.test');
    $git($remote, 'add', 'repro.sh');
    $git($remote, 'commit', '-m', 'main');
    $stale = $git($remote, 'rev-parse', 'HEAD');

    $clone = temporaryPath('orbit-delivery-clone-', 6);
    (new Process(['git', 'clone', $remote, $clone]))->mustRun();

    file_put_contents($remote.'/repro.sh', "#!/bin/sh\nexit 2\n");
    $git($remote, 'add', 'repro.sh');
    $git($remote, 'commit', '-m', 'newer main');

    return [
        'root' => $clone,
        'stale' => $stale,
        'current' => $git($remote, 'rev-parse', 'HEAD'),
    ];
}

/** @return array{root: string, main: string} */
function deliveryLineFreshOriginRepo(): array
{
    $remote = temporaryPath('orbit-delivery-fresh-remote-', 6);
    mkdir($remote, 0o700, true);
    file_put_contents($remote.'/repro.sh', "#!/bin/sh\nexit 1\n");
    chmod($remote.'/repro.sh', 0o700);
    $git = static function (string $root, string ...$arguments): string {
        $process = new Process(['git', '-C', $root, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $git($remote, 'init', '-b', 'main');
    $git($remote, 'config', 'user.name', 'Orbit');
    $git($remote, 'config', 'user.email', 'orbit@example.test');
    $git($remote, 'add', 'repro.sh');
    $git($remote, 'commit', '-m', 'main');
    $main = $git($remote, 'rev-parse', 'HEAD');

    $clone = temporaryPath('orbit-delivery-fresh-clone-', 6);
    (new Process(['git', 'clone', $remote, $clone]))->mustRun();

    return ['root' => $clone, 'main' => $main];
}

describe('delivery-line proof commands', function (): void {
    it('prints rich help for each command', function (string $script): void {
        $result = deliveryLineRun($script, ['--help']);

        expect($result['exit'])->toBe(0)
            ->and($result['stdout'])->toContain('usage:')
            ->and($result['stdout'])->toContain('Print one JSON object')
            ->and($result['stdout'])->not->toContain("\e[");
        if ($script === 'bug-repro') {
            expect($result['stdout'])->toContain('git ls-remote origin main')
                ->and($result['stdout'])->toContain('main_stale');
        }
        if ($script === 'deploy-verify') {
            expect($result['stdout'])->toContain('SSL_CERT_FILE')
                ->and($result['stdout'])->toContain("Orbit's root CA");
        }
        if ($script === 'pr-head-check') {
            expect($result['stdout'])->toContain('--slurp')
                ->and($result['stdout'])->toContain('COMMENTED');
        }
    })->with([
        'bug-repro' => ['bug-repro'],
        'task-group-check' => ['task-group-check'],
        'pr-head-check' => ['pr-head-check'],
        'deploy-verify' => ['deploy-verify'],
    ]);

    it('refuses to name a cached origin/main that does not match git ls-remote', function (): void {
        $repo = deliveryLineStaleOriginRepo();
        $result = deliveryLineRun('bug-repro', [
            '--repository', $repo['root'],
            '--command', './repro.sh',
            '--paths', 'repro.sh',
            '--dry-run',
        ], $repo['root']);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('main_stale')
            ->and($result['json']['local_sha'] ?? null)->toBe($repo['stale'])
            ->and($result['json']['origin_sha'] ?? null)->toBe($repo['current'])
            ->and($result['stderr'])->toContain('Fetch origin main');
    });

    it('names main when the local origin/main SHA matches git ls-remote', function (): void {
        $repo = deliveryLineFreshOriginRepo();
        $result = deliveryLineRun('bug-repro', [
            '--repository', $repo['root'],
            '--command', './repro.sh',
            '--paths', 'repro.sh',
            '--dry-run',
        ], $repo['root']);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['dry_run'] ?? null)->toBeTrue()
            ->and($result['json']['main_sha'] ?? null)->toBe($repo['main']);
    });

    it('reproduces a command that exits nonzero on current main', function (): void {
        $repo = deliveryLineRepo();
        file_put_contents($repo['root'].'/repro.sh', "#!/bin/sh\nexit 7\n");
        $result = deliveryLineRun('bug-repro', [
            '--repository', $repo['root'],
            '--main', 'main',
            '--command', './repro.sh',
            '--paths', 'repro.sh',
        ], $repo['root']);

        expect($result['exit'])->toBe(0)
            ->and($result['json'])->toMatchArray([
                'passed' => true,
                'main_sha' => $repo['main'],
                'command' => './repro.sh',
                'exit_code' => 7,
                'paths' => ['repro.sh'],
            ]);
    });

    it('refuses a command that exits 0 on current main and does not file a task', function (): void {
        $repo = deliveryLineRepo();
        $result = deliveryLineRun('bug-repro', [
            '--repository', $repo['root'],
            '--main', 'main',
            '--command', './repro.sh',
            '--paths', 'repro.sh',
        ], $repo['root']);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('not_reproduced')
            ->and($result['json']['exit_code'] ?? null)->toBe(0)
            ->and($result['stderr'])->toContain('Do not file a task');
    });

    it('prints a dry-run for bug-repro without running the command', function (): void {
        $repo = deliveryLineRepo();
        file_put_contents($repo['root'].'/repro.sh', "#!/bin/sh\nprintf ran > ran.txt\nexit 1\n");
        $result = deliveryLineRun('bug-repro', [
            '--repository', $repo['root'],
            '--main', 'main',
            '--command', './repro.sh',
            '--paths', 'repro.sh',
            '--dry-run',
        ], $repo['root']);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['dry_run'] ?? null)->toBeTrue()
            ->and($result['json']['main_sha'] ?? null)->toBe($repo['main'])
            ->and(file_exists($repo['root'].'/ran.txt'))->toBeFalse();
    });

    it('accepts the repository task-group shape from the Orbit Tasks skill', function (): void {
        $result = deliveryLineRun('task-group-check', [
            '--payload', deliveryLineFixture('task-group-pass.json'),
            '--kind', 'auto',
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json'])->toMatchArray([
                'passed' => true,
                'kind' => 'bug',
                'subtasks' => 2,
                'fails_on_base' => true,
            ]);
    });

    it('rejects a subtask that only restates the final gate', function (): void {
        $result = deliveryLineRun('task-group-check', [
            '--payload', deliveryLineFixture('task-group-fail-gate.json'),
            '--kind', 'feature',
        ]);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('final_gate')
            ->and($result['stderr'])->toContain('Do not add a gate-only subtask');
    });

    it('rejects a bug group with no failing command', function (): void {
        $path = temporaryPath('orbit-delivery-bug-missing-', 6).'.json';
        file_put_contents($path, json_encode([
            'project_id' => 1,
            'title' => 'Doctor instance.php_fpm_projection_mismatch on instance 12',
            'brief' => 'Filed by the outer loop.',
            'tasks' => [[
                'title' => 'Fix the mismatch',
                'brief' => "**Goal:** Fix the projection.\n\n**Acceptance:** The Instance matches.",
                'deliverables' => [[
                    'id' => 'fix',
                    'type' => 'review',
                    'description' => 'Fix the projection',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));
        $result = deliveryLineRun('task-group-check', ['--payload', $path, '--kind', 'bug']);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('fails_on_base_missing')
            ->and($result['stderr'])->toContain('fails_on_base true');
    });

    it('fails PR 945 when the review list is empty', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/945',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-945-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-945-checks.json'),
            '--files-file', deliveryLineFixture('pr-945-files.json'),
        ]);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('review_missing')
            ->and($result['json']['head_sha'] ?? null)->toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->and($result['stderr'])->toContain('Do not merge');
    });

    it('passes pr-head-check when the review, Required checks, and diff match the head', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/1',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-pass-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-945-checks.json'),
            '--files-file', deliveryLineFixture('pr-945-files.json'),
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['passed'] ?? null)->toBeTrue()
            ->and($result['json']['kept_reviews'] ?? null)->toBe(1)
            ->and(data_get($result['json'], 'required_checks.conclusion'))->toBe('success');
    });

    it('finds Required checks in a slurped one-element array of the check-runs object', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/1',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-pass-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-slurped-checks.json'),
            '--files-file', deliveryLineFixture('pr-945-files.json'),
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['passed'] ?? null)->toBeTrue()
            ->and($result['json']['error'] ?? null)->toBeNull()
            ->and(data_get($result['json'], 'required_checks.conclusion'))->toBe('success')
            ->and(data_get($result['json'], 'required_checks.head_sha'))->toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    });

    it('keeps a COMMENTED review on the current head and does not require APPROVED', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/1',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-commented-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-945-checks.json'),
            '--files-file', deliveryLineFixture('pr-945-files.json'),
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['passed'] ?? null)->toBeTrue()
            ->and($result['json']['kept_reviews'] ?? null)->toBe(1);
    });

    it('names a tasks:merge leftover and refuses to merge', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/1',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-pass-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-945-checks.json'),
            '--files-file', deliveryLineFixture('pr-leftover-files.json'),
        ]);

        expect($result['exit'])->toBe(1)
            ->and($result['json']['error'] ?? null)->toBe('leftover')
            ->and($result['stderr'])->toContain('Do not merge');
    });

    it('does not treat the detector, leftover-refusal test, or leftover fixture as product leftovers', function (): void {
        $result = deliveryLineRun('pr-head-check', [
            '--pr', 'https://github.com/nckrtl/orbit/pull/946',
            '--pull-file', deliveryLineFixture('pr-945-pull.json'),
            '--reviews-file', deliveryLineFixture('pr-commented-reviews.json'),
            '--checks-file', deliveryLineFixture('pr-945-checks.json'),
            '--files-file', deliveryLineFixture('pr-946-files.json'),
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json']['passed'] ?? null)->toBeTrue()
            ->and($result['json']['error'] ?? null)->toBeNull()
            ->and($result['json']['leftovers'] ?? null)->toBe([])
            ->and($result['json']['kept_reviews'] ?? null)->toBe(1);
    });

    it('prints deploy-verify dry-run URLs without calling them', function (): void {
        $result = deliveryLineRun('deploy-verify', [
            '--sha', '2f214816deaed1d961f4a64c7f40762088c64226',
            '--dry-run',
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json'])->toMatchArray([
                'dry_run' => true,
                'source' => 'dry-run',
                'expected_sha' => '2f214816deaed1d961f4a64c7f40762088c64226',
                'up_url' => 'https://gateway.orbit/up',
                'status_url' => 'https://gateway.orbit/api/v1/gateway/status',
            ]);
    });

    it('passes deploy-verify against the recorded 2026-10-01 marker fixture', function (): void {
        $result = deliveryLineRun('deploy-verify', [
            '--sha', '2f214816deaed1d961f4a64c7f40762088c64226',
            '--fixture', deliveryLineFixture('verified-merge-2026-10-01.json'),
        ]);

        expect($result['exit'])->toBe(0)
            ->and($result['json'])->toMatchArray([
                'passed' => true,
                'source' => 'fixture',
                'app_version' => '2f214816deae',
            ])
            ->and(data_get($result['json'], 'up.ok'))->toBeTrue()
            ->and(data_get($result['json'], 'gateway_status.ok'))->toBeTrue();
    });

    it('names SSL_CERT_FILE when a live call fails certificate verification', function (): void {
        $directory = temporaryPath('orbit-delivery-tls-', 6);
        mkdir($directory, 0o700, true);
        $key = $directory.'/key.pem';
        $cert = $directory.'/cert.pem';
        (new Process([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', $key, '-out', $cert, '-days', '1', '-nodes',
            '-subj', '/CN=127.0.0.1',
        ]))->mustRun();

        $server = new Process([
            'python3', '-c',
            <<<'PY'
import http.server
import ssl
import sys

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(b'{"status":"ok"}')

    def log_message(self, *_args):
        return

httpd = http.server.HTTPServer(('127.0.0.1', 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
print(httpd.server_port, flush=True)
httpd.handle_request()
PY
            , $cert, $key,
        ]);
        $server->start();
        $port = null;
        $deadline = microtime(true) + 5;
        while ($port === null && microtime(true) < $deadline) {
            $output = trim($server->getOutput());
            if ($output !== '') {
                $port = $output;
            }
            usleep(20_000);
        }
        expect($port)->not->toBeNull();

        $url = 'https://127.0.0.1:'.$port.'/up';
        $process = new Process([
            dirname(__DIR__, 5).'/bin/deploy-verify',
            '--sha', '2f214816deae',
            '--up-url', $url,
            '--status-url', $url,
        ], dirname(__DIR__, 5), ['SSL_CERT_FILE' => '']);
        $process->setTimeout(15);
        $process->run();
        $server->stop();

        $decoded = json_decode($process->getOutput(), true);
        expect($process->getExitCode())->toBe(1)
            ->and(is_array($decoded))->toBeTrue()
            ->and($decoded['error'] ?? null)->toBe('unreachable')
            ->and($decoded['next'] ?? '')->toContain('SSL_CERT_FILE')
            ->and($process->getErrorOutput())->toContain('SSL_CERT_FILE');
    });
});
