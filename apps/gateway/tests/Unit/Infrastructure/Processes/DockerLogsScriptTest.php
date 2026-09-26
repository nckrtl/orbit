<?php

declare(strict_types=1);

use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use Symfony\Component\Process\Process;

it('returns standard output and standard error of a container in the order it wrote them', function (): void {
    $bin = sys_get_temp_dir().'/orbit-docker-logs-'.bin2hex(random_bytes(4));
    mkdir($bin);
    // A stand-in for the Docker CLI, which writes each stream of `docker container logs` to its own descriptor.
    file_put_contents($bin.'/docker', <<<'SH'
        #!/bin/sh
        test "$1 $2 $3 $4 $5" = "container logs --tail 20 orbit-process-7-web" || exit 9
        echo out one
        echo err one >&2
        echo out two
        echo err two >&2
        SH);
    chmod($bin.'/docker', 0755);

    $process = new Process(['sh', '-c', RemoteProcessRuntimeManager::DockerLogsScript, 'orbit-process-logs', '20', 'orbit-process-7-web'], env: ['PATH' => $bin.':/usr/bin:/bin']);
    $process->mustRun();

    expect($process->getOutput())->toBe("out one\nerr one\nout two\nerr two\n")
        ->and($process->getErrorOutput())->toBe('');

    unlink($bin.'/docker');
    rmdir($bin);
});
