<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;
use Tests\TestCase;

uses(TestCase::class);

describe(CustomProxyProcessListener::class, function (): void {
    it('resolves exactly one published loopback listener to 127.0.0.1', function (array $ports): void {
        $upstream = new CustomProxyProcessListener()->resolve(listener_process($ports));

        expect($upstream->url())->toBe('http://127.0.0.1:4788');
    })->with([
        'loopback' => [['127.0.0.1:4788:80/tcp']],
        'all interfaces' => [['0.0.0.0:4788:80/tcp']],
        'ipv6 loopback' => [['::1:4788:80/tcp']],
    ]);

    it('refuses a Process without one Node-local listener', function (ProcessRuntime $runtime, array $ports): void {
        expect(fn () => new CustomProxyProcessListener()->resolve(listener_process($ports, $runtime)))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.upstream_unresolved');
            });
    })->with([
        'no ports' => [ProcessRuntime::Docker, []],
        'two loopback ports' => [ProcessRuntime::Docker, ['127.0.0.1:4788:80/tcp', '127.0.0.1:4789:81/tcp']],
        'non-loopback' => [ProcessRuntime::Docker, ['10.44.0.8:4788:80/tcp']],
        'systemd' => [ProcessRuntime::Systemd, ['127.0.0.1:4788:80/tcp']],
    ]);
});

function listener_process(array $ports, ProcessRuntime $runtime = ProcessRuntime::Docker): Process
{
    $process = new Process;
    $process->name = 'executor';
    $process->runtime = $runtime;
    $process->runtime_config = ['ports' => $ports];

    return $process;
}
