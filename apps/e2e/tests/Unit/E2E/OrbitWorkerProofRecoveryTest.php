<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retains proof recovery state until the final process audit succeeds', function (int $readExit, string $processes, bool $passes): void {
    $sandbox = temporaryPath('orbit-worker-audit-', 6);
    mkdir($sandbox.'/bin', 0700, true);
    $state = $sandbox.'/state.json';
    file_put_contents($state, '{"group":42}');
    file_put_contents($sandbox.'/bin/orbit', "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($processes)."\nexit {$readExit}\n");
    chmod($sandbox.'/bin/orbit', 0700);
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/proofs/orbit-worker.sh');
    // Execute the actual cleanup body. Only the guest transport and CLI response
    // are fixtures; the final audit, pipefail and state removal remain real.
    preg_match('/cleanup\(\) \{.*?\n\}\n(?=trap cleanup EXIT)/s', $source, $matches);
    expect($matches)->not->toBeEmpty();
    $cleanup = str_replace('/home/orbit/orbit-worker-proof.json', $state, $matches[0]);
    $script = <<<'BASH'
        set -euo pipefail
        process_attempted=0
        process_id=
        task_started=1
        checkout=
        provider_started=0
        worker_created=0
        on() {
            label=$3
            shift 3
            if [ "$label" = worker-gateway-removal ]; then
                printf 'GATEWAY_REMOVAL_AUDIT {"checkout":null}\n'
            else
                [ "$label" = worker-final-audit ]
                # Do not load a guest login profile in this isolated host fixture.
                bash -c "$3"
            fi
        }
        BASH;
    $process = new Process(['bash', '-c', $script."\n".$cleanup."\ncleanup"], env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')]);

    $process->run();

    expect($process->isSuccessful())->toBe($passes);
    expect(file_exists($state))->toBe(! $passes);
    if (! $passes) {
        expect(file_get_contents($state))->toBe('{"group":42}');
        expect($process->getOutput())->not->toContain('WORKER_FINAL_AUDIT_PASSED');
    } else {
        expect($process->getOutput())->toContain('WORKER_FINAL_AUDIT_PASSED');
    }
})->with([
    'CLI read failed despite valid JSON' => [1, '{"processes":[]}', false],
    'Process remains' => [0, '{"processes":[{"name":"pi-server"}]}', false],
    'invalid readback' => [0, 'not-json', false],
    'clean audit' => [0, '{"processes":[]}', true],
]);
