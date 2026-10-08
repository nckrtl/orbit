<?php

declare(strict_types=1);

use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;

it('refuses to bootstrap a Gateway release in place', function (): void {
    $release = sys_get_temp_dir().'/orbit-release-guard-'.bin2hex(random_bytes(4));
    mkdir($release.'/bin', 0700, true);
    copy(dirname(base_path(), 2).'/bin/bootstrap', $release.'/bin/bootstrap');
    file_put_contents($release.'/REVISION', str_repeat('a', 40)."\n");

    try {
        $result = new NativeProcessRunner()->run(new ProcessInvocation(['bash', $release.'/bin/bootstrap'], timeout: 30.0));
    } finally {
        exec('rm -rf '.escapeshellarg($release));
    }

    expect($result->exitCode)->toBe(2)
        ->and($result->stderr)->toContain('immutable Gateway release');
});
