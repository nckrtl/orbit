<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('preserves allocated probe databases but isolates parallel worker scopes', function (bool $worker): void {
    $parent = sys_get_temp_dir();
    $token = getenv('TEST_TOKEN') ?: '';
    $program = <<<'PHP'
        require 'vendor/autoload.php';
        Tests\Support\TestTemporaryDirectory::bootstrap();
        echo sys_get_temp_dir();
        PHP;
    $process = new Process([PHP_BINARY, '-r', $program], dirname(__DIR__, 3), ['TEST_TOKEN' => $worker ? $token.'-other' : $token]);
    $process->mustRun();
    $root = $process->getOutput();
    expect(is_dir($parent))->toBeTrue();
    if ($worker) {
        expect($root)->not->toBe($parent)->and(file_exists($root))->toBeFalse();
    } else {
        expect($root)->toBe($parent);
    }
})->with([false, true]);

it('isolates test fixtures from long writable runner temp ancestry and cleans only its own scope', function (): void {
    $supplied = sys_get_temp_dir().'/'.str_repeat('runner-', 20);
    mkdir($supplied, 0775);
    chmod($supplied, 0775);
    file_put_contents($supplied.'/keep', 'runner-owned');
    try {
        $program = <<<'PHP'
            require 'vendor/autoload.php';
            Tests\Support\TestTemporaryDirectory::bootstrap();
            $root = sys_get_temp_dir();
            $socket = stream_socket_server('unix://'.$root.'/socket');
            if ($socket === false) { exit(1); }
            fclose($socket);
            echo json_encode(['root' => $root, 'mode' => fileperms($root) & 0777,
                'owner' => fileowner($root), 'uid' => posix_geteuid(), 'environment' => getenv('TMPDIR')], JSON_THROW_ON_ERROR);
            PHP;
        $process = new Process([PHP_BINARY, '-r', $program], dirname(__DIR__, 3), ['TMPDIR' => $supplied]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['mode'])->toBe(0711)->and($result['owner'])->toBe($result['uid'])
            ->and($result['root'])->toStartWith(realpath('/tmp').'/ot-')
            ->and(strlen($result['root']))->toBeLessThan(40)
            ->and($result['environment'])->toBe($result['root'])
            ->and(file_exists($result['root']))->toBeFalse()
            ->and(file_get_contents($supplied.'/keep'))->toBe('runner-owned');
    } finally {
        new Filesystem()->deleteDirectory($supplied);
    }
});
