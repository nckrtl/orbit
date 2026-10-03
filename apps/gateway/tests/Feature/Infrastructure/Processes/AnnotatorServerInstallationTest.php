<?php

declare(strict_types=1);

use App\Domain\Processes\AnnotatorPreset;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\AnnotatorServerInstallation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('refuses a missing or stale distribution asset before publishing an installation command', function (bool $stale): void {
    $assets = sys_get_temp_dir().'/orbit-annotator-assets-'.bin2hex(random_bytes(8));
    new Filesystem()->ensureDirectoryExists($assets);
    try {
        if ($stale) {
            new Filesystem()->copyDirectory(resource_path('annotator'), $assets);
            file_put_contents($assets.'/manifest.json', json_encode(['source_sha256' => 'old-source', 'asset_sha256' => hash_file('sha256', $assets.'/inject.js.gz')], JSON_THROW_ON_ERROR));
        }
        expect(fn () => new AnnotatorServerInstallation(assetDirectory: $assets)->command())
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($stale ? 'process.annotator_asset_stale' : 'process.annotator_asset_missing'));
    } finally {
        new Filesystem()->deleteDirectory($assets);
    }
})->with([false, true]);

it('refuses an injection asset after a build configuration or dependency lock change', function (string $input): void {
    $source = sys_get_temp_dir().'/orbit-annotator-source-'.bin2hex(random_bytes(8));
    $package = base_path('../../packages/agent-annotation');
    $files = new Filesystem;
    $files->ensureDirectoryExists($source);
    try {
        foreach (['src', 'bin'] as $directory) {
            $files->copyDirectory($package.'/'.$directory, $source.'/'.$directory);
        }
        foreach (['package.json', 'vite.config.ts', 'tsconfig.json', 'bun.lock'] as $file) {
            $files->copy($package.'/'.$file, $source.'/'.$file);
        }
        expect(AnnotatorServerInstallation::sourceDigest($source))->toBe(AnnotatorServerInstallation::sourceDigest($package));
        file_put_contents($source.'/'.$input, "\nchanged build input", FILE_APPEND);
        expect(fn () => new AnnotatorServerInstallation(packagePath: $source)->command())
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('process.annotator_asset_stale'));
    } finally {
        $files->deleteDirectory($source);
    }
})->with(['tsconfig.json', 'bun.lock']);

it('serves the installed injection asset and admits only the rendered page and T3 origins for queue SSE deletion and preflight', function (): void {
    $root = sys_get_temp_dir().'/orbit-annotator-endpoint-'.bin2hex(random_bytes(8));
    $command = new AnnotatorServerInstallation()->command();
    $program = str_replace("pathlib.Path('/opt/orbit/annotator')", 'pathlib.Path('.json_encode($root, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).')', $command->arguments[3]);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    assert(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    assert(is_string($address));
    $port = (int) substr($address, strrpos($address, ':') + 1);
    fclose($socket);
    $instance = new Instance(['annotator_port' => $port]);
    $instance->id = 1;
    $target = new ProcessTarget(node: new Node, user: 'orbit', checkoutPath: $root, instance: $instance, routeDomain: 'site.test');
    $arguments = AnnotatorPreset::forTarget($target);
    $arguments[1] = $root.'/current/bin/serve.mjs';
    $arguments[array_search('--store', $arguments, true) + 1] = $root.'/store';
    $server = new Process($arguments);
    try {
        new Process(['python3', '-c', $program], input: $command->input)->mustRun();
        $server->start();
        $check = new Process(['node', '--input-type=module', '-e', <<<'JS'
            import assert from 'node:assert/strict';
            import { readFile } from 'node:fs/promises';
            const origin = `http://127.0.0.1:${process.argv[1]}`;
            let ready = false;
            // CI can delay process startup; bound readiness by elapsed time, not poll count.
            const deadline = performance.now() + 15000;
            while (performance.now() < deadline) {
              try { ready = (await fetch(origin + '/health', {signal: AbortSignal.timeout(1000)})).status === 200; } catch {}
              if (ready) break;
              await new Promise(resolve => setTimeout(resolve, 100));
            }
            assert.equal(ready, true, 'annotator /health did not become ready within 15 seconds');
            for (const allowed of ['https://site.test', 't3code://app', 't3code-dev://app']) {
              for (const method of ['GET', 'DELETE', 'OPTIONS']) {
                const response = await fetch(origin + '/annotations', {method, headers: {Origin: allowed, 'Access-Control-Request-Method': 'DELETE'}});
                assert.equal(response.status, method === 'OPTIONS' ? 204 : 200);
                assert.equal(response.headers.get('access-control-allow-origin'), allowed);
                assert.match(response.headers.get('access-control-allow-methods'), /DELETE/);
              }
              const abort = new AbortController();
              const response = await fetch(origin + '/annotations/events', {headers: {Origin: allowed}, signal: abort.signal});
              assert.equal(response.status, 200);
              assert.match(response.headers.get('content-type'), /text\/event-stream/);
              assert.match(new TextDecoder().decode((await response.body.getReader().read()).value), /data: /);
              abort.abort();
            }
            for (const forbidden of ['https://evil.test', 't3code://other']) {
              for (const [path, method] of [['/annotations','GET'], ['/annotations/events','GET'], ['/annotations','DELETE'], ['/annotations','OPTIONS']]) {
                const response = await fetch(origin + path, {method, headers: {Origin: forbidden}});
                assert.equal(response.status, 403);
              }
            }
            const injection = await fetch(origin + '/inject.js');
            assert.equal(injection.status, 200);
            assert.match(injection.headers.get('content-type'), /javascript/);
            assert.equal(await injection.text(), await readFile(process.argv[2], 'utf8'));
            console.log('verified installed asset and preset origins');
            JS, (string) $port, $root.'/current/dist/inject.js'], timeout: 30);
        $check->mustRun();
        expect($check->getOutput())->toContain('verified installed asset and preset origins');
    } finally {
        $server->stop(1);
        new Filesystem()->deleteDirectory($root);
    }
});

it('publishes the Gateway files atomically and retains an immutable release for running servers', function (): void {
    $root = sys_get_temp_dir().'/orbit-annotator-install-'.bin2hex(random_bytes(8));
    $command = new AnnotatorServerInstallation()->command();
    $program = str_replace("pathlib.Path('/opt/orbit/annotator')", 'pathlib.Path('.json_encode($root, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).')', $command->arguments[3]);
    try {
        new Process(['python3', '-c', $program], input: $command->input)->mustRun();
        $original = realpath($root.'/current');
        expect(is_link($root.'/current'))->toBeTrue()
            ->and(file_get_contents($root.'/current/bin/serve.mjs'))->toBe(file_get_contents(base_path('../../packages/agent-annotation/bin/serve.mjs')))
            ->and(fileperms($root.'/current/bin/serve.mjs') & 0777)->toBe(0644);
        new Process(['python3', '-c', $program], input: $command->input)->mustRun();
        expect(realpath($root.'/current'))->toBe($original);
        $changed = json_decode($command->input, true, flags: JSON_THROW_ON_ERROR);
        $changed['bin/SKILL.md'] .= '\nNew version';
        new Process(['python3', '-c', $program], input: json_encode($changed, JSON_THROW_ON_ERROR))->mustRun();
        clearstatcache(true);
        expect(realpath($root.'/current'))->not->toBe($original)->and(is_dir($original))->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
