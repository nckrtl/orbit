<?php

declare(strict_types=1);

use App\Infrastructure\Analytics\AnalyticsCaddyPublisher;
use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use App\Infrastructure\AppProd\AppProdCaddyPublisher;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use Symfony\Component\Process\Process;

it('selects the runtime identity before validating each retained aggregate', function (Closure $commands): void {
    foreach ($commands() as $script) {
        expect($script)->toContain('runuser -u caddy -- caddy validate --config "$candidate/Caddyfile" --adapter caddyfile');
        expect(preg_match('/^\s*caddy validate/m', $script))->toBe(0);
    }
})->with([
    'app-dev' => fn (): array => [
        new AppDevCaddyPublisher()->command('# site', 'test')->input,
        new AppDevCaddyPublisher()->removeCommand('test')->input,
    ],
    'app-prod' => fn (): array => [
        new AppProdCaddyPublisher()->command('# site', 'test')->input,
        new AppProdCaddyPublisher()->removeCommand('test')->input,
    ],
    'analytics' => fn (): array => [
        new AnalyticsCaddyPublisher()->command('# site', '8000', '10.44.0.1')->input,
        new AnalyticsCaddyPublisher()->removeCommand()->input,
    ],
    'proxycli' => fn (): array => [
        new ProxyCliCaddyPublisher()->command('# site', '8000', '10.44.0.1')->input,
        new ProxyCliCaddyPublisher()->removeCommand()->input,
    ],
    'websocket' => fn (): array => [
        new WebSocketCaddyPublisher()->command('# site', '8000', '10.44.0.1')->input,
        new WebSocketCaddyPublisher()->removeCommand()->input,
    ],
    'metrics' => function (): array {
        $processes = new class implements ProcessRunner
        {
            /** @var list<string> */
            public array $scripts = [];

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->scripts[] = $invocation->input ?? '';

                return new CommandResult(0, 'orbit-metrics-publication:created', '', 0, false);
            }
        };
        $publisher = new MetricsCaddyPublisher($processes);
        $publisher->publish('# site');
        $publisher->remove();

        return $processes->scripts;
    },
]);

it('publishes and reloads with runtime-owned logs and refuses inaccessible existing logs', function (): void {
    if (getenv('ORBIT_NATIVE_CADDY') !== '1') {
        $this->markTestSkipped('Requires root, real Caddy and its service account in an isolated Linux environment.');
    }

    $root = '/var/tmp/orbit-caddy-native-'.bin2hex(random_bytes(8));
    $manifest = new Process([PHP_BINARY, base_path('tests/Fixtures/caddy-validation-manifest.php'), $root]);
    $manifest->mustRun();
    $native = new Process(['python3', base_path('tests/Fixtures/caddy-validation-native.py')]);
    $native->setInput($manifest->getOutput());
    $native->setTimeout(60);
    $native->run();

    expect($native->getExitCode())->toBe(0, $native->getErrorOutput());
    expect(json_decode($native->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        'publish' => 'passed',
        'reload' => 'passed',
        'invalid_rollback' => 'passed',
        'inaccessible_log_refused' => 'passed',
        'retry' => 'passed',
        'removal' => 'passed',
        'unrelated_preserved' => 'passed',
        'certificate_preserved' => 'passed',
        'log_mode' => '0600',
    ]);
    expect(is_dir($root))->toBeFalse();
});
