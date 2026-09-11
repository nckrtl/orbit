<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

it('starts the configured controller when called outside the repository', function (): void {
    $root = temporaryPath('orbit-loop-launcher-', 6);
    $caller = temporaryPath('orbit-loop-caller-', 6);
    mkdir($root.'/bin', 0700, true);
    mkdir($caller, 0700, true);
    copy(dirname(__DIR__, 5).'/bin/loop', $root.'/bin/loop');
    chmod($root.'/bin/loop', 0755);
    $driver = $root.'/delivery-controller';
    file_put_contents(
        $driver,
        "#!/usr/bin/env python3\nimport json, os, sys\nprint(json.dumps({'argv': sys.argv, 'cwd': os.getcwd()}))\n",
    );
    chmod($driver, 0755);
    $run = new ProcessFactory;
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'orbit.deliveryDriver', $driver],
    ] as $arguments) {
        expect($run->path($root)->run(['git', ...$arguments])->successful())->toBeTrue();
    }

    $result = $run->path($caller)->run([$root.'/bin/loop', 'TEST-999', 'status']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'argv' => [$driver, '--repository', $root, 'TEST-999', 'status'],
            'cwd' => $caller,
        ]);
});
