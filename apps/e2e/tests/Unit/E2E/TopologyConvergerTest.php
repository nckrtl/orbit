<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyConverger;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract in unit tests. */
    Facade::setFacadeApplication($container);
});

function task7_host(): IncusHost
{
    return new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
}

function task7_vm(string $name, string $owner = 'orbit-e2e'): string
{
    $role = str_ends_with($name, '-gateway') ? 'gateway' : (str_ends_with($name, '-app-dev') ? 'app-dev' : 'app-prod');
    $network = 'oe-0799eee4eaf4';
    $hash = substr(sha1("{$network}:{$role}"), 0, 6);
    $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
    $ipv4 = ['gateway' => '10.232.2.10', 'app-dev' => '10.232.2.11', 'app-prod' => '10.232.2.12'][$role];

    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => [
            'root' => ['pool' => 'orbit-e2e'],
            'eth0' => ['network' => $network, 'ipv4.address' => $ipv4, 'hwaddr' => $mac],
        ],
    ]], JSON_THROW_ON_ERROR);
}

function task7_recipe_vm(TopologyTarget $target, string $node, string $owner = 'orbit-e2e'): string
{
    return json_encode([[
        'name' => $target->instance($node),
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => [
            'root' => ['pool' => 'orbit-e2e'],
            'eth0' => [
                'network' => $target->network(),
                'ipv4.address' => TopologyTarget::ipv4For(2, $target->recipe->node($node)->address),
                'hwaddr' => $target->mac($node),
            ],
        ],
    ]], JSON_THROW_ON_ERROR);
}

function task7_gateway_public_key(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO';
}

/** @param list<string> $command */
function task7_is_global_ipv4_probe(array $command): bool
{
    return ($command[0] ?? null) === 'sh'
    && ($command[1] ?? null) === '-c'
    && str_contains((string) ($command[2] ?? ''), 'route show default');
}

/** @param list<string> $command */
function task7_ipv4(array $command): ?string
{
    if (! task7_is_global_ipv4_probe($command)) {
        return null;
    }

    $target = implode(' ', $command);

    return match (true) {
        str_contains($target, 'gateway') => '192.0.2.10',
        str_contains($target, 'app-dev') => '192.0.2.11',
        default => '192.0.2.12',
    };
}

/** @param list<list<string>> $recorded */
/** @mago-expect lint:cyclomatic-complexity The process fake models all ordered convergence responses in one test boundary. */
function task7_process_result(
    PendingProcess $process,
    array &$recorded,
    bool $typed = false,
    ?TopologyTarget $target = null,
): ProcessResult {
    $command = $process->command;
    assert(is_array($command));
    if (str_ends_with((string) ($command[1] ?? ''), '/resources/host/exec-all.py')) {
        $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($payload['requests'] as $request) {
            $argv = $request['argv'];
            $recorded[] = ['incus', '--project', 'orbit', 'exec', $request['instance'], '--', ...$argv];
            if ($argv === ['uname', '-m']) {
                $architecture = $request['label'] === 'app-dev' ? 'x86_64' : 'aarch64';
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => $architecture."\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ];
                continue;
            }
            if (task7_is_global_ipv4_probe($argv)) {
                $address = $target === null
                    ? (
                        str_contains($request['instance'], 'gateway')
                            ? '192.0.2.10'
                            : (str_contains($request['instance'], 'app-dev') ? '192.0.2.11' : '192.0.2.12')
                    )
                    : '192.0.2.'.$target->recipe->node($request['label'])->address;
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => "2: enp5s0    inet {$address}/24 scope global enp5s0\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ];
                continue;
            }
            $nested = new PendingProcess(app(ProcessFactory::class));
            $nested->command = $argv;
            $nestedRecorded = [];
            $result = task7_process_result($nested, $nestedRecorded, $typed, $target);
            $results[] = [
                'label' => $request['label'],
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ];
        }

        return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
    }
    $recorded[] = $command;

    $address = task7_ipv4($command);
    if ($address !== null) {
        return Process::result("2: enp5s0    inet {$address}/24 scope global enp5s0\n");
    }

    if (in_array('ssh-keygen', $command, true) && in_array('-y', $command, true)) {
        return Process::result(task7_gateway_public_key()." orbit-gateway\n");
    }

    if (in_array('uname', $command, true) && str_contains(implode(' ', $command), 'app-dev')) {
        return Process::result("x86_64\n");
    }

    if (in_array('uname', $command, true) && str_contains(implode(' ', $command), 'app-prod')) {
        return Process::result("aarch64\n");
    }

    if ($typed && in_array('create-resources', $command, true)) {
        return Process::result(json_encode([
            'shape' => 'app_instances',
            'app_id' => 1,
            'node_id' => 2,
            'name' => 'e2e-dev',
            'checkout_path' => '/srv/orbit/apps/laravel-typed/e2e-dev',
            'effective_root' => 'public',
        ], JSON_THROW_ON_ERROR)
            ."\n");
    }

    if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
        $network = $target?->network() ?? 'oe-0799eee4eaf4';

        return Process::result(json_encode([[
            'name' => $network,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
        ]], JSON_THROW_ON_ERROR));
    }

    if (($command[count($command) - 1] ?? null) === '--format=json') {
        if (in_array('network', $command, true)) {
            return Process::result(json_encode([[
                'name' => 'oe-0799eee4eaf4',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
            ]], JSON_THROW_ON_ERROR));
        }
        if (($command[count($command) - 2] ?? null) === 'lab:') {
            if ($target !== null) {
                return Process::result(json_encode(array_map(
                    static fn (string $node): array => json_decode(
                        task7_recipe_vm($target, $node),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    $target->recipe->nodeKeys(),
                ), JSON_THROW_ON_ERROR));
            }

            return Process::result(json_encode(
                array_map(
                    static fn (string $role): array => json_decode(
                        task7_vm('orbit-e2e-nck-123-aaaaaaaa-'.$role),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['gateway', 'app-dev', 'app-prod'],
                ),
                JSON_THROW_ON_ERROR,
            ));
        }
        $selector = $command[count($command) - 2];

        if ($target !== null) {
            $instance = str_contains($selector, ':') ? substr($selector, strpos($selector, ':') + 1) : $selector;
            $node = array_find(
                $target->recipe->nodeKeys(),
                fn (string $node): bool => $target->instance($node) === $instance,
            );
            if (is_string($node)) {
                return Process::result(task7_recipe_vm($target, $node));
            }
        }

        return Process::result(task7_vm(
            str_contains($selector, ':') ? substr($selector, strpos($selector, ':') + 1) : $selector,
        ));
    }

    return Process::result();
}

/** @mago-expect lint:cyclomatic-complexity The fixture preserves one complete ordered convergence contract. */
describe('TopologyConverger', function () {
    it('validates and converges an existing ready topology in the required order', function () {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded): ProcessResult {
            return task7_process_result($process, $recorded);
        });

        $sha = str_repeat('a', 40);
        $report = new TopologyConverger(task7_host())->converge(
            featureTarget('NCK-123'),
            new SourceState($sha, $sha, false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        );

        expect(array_keys($report->steps))->toBe([
            'validate.prerequisites',
            'align.identity',
            'prerequisites.gateway',
            'bootstrap.gateway',
            'authorize.gateway-ssh',
            'retarget.vpn',
            'provision.app-dev',
            'provision.app-prod',
            'authorize.app-dev-operator',
            'configure.app-dev-cli',
            'create.sample-resources',
            'converge.metrics',
            'reproject.product-state',
            'refresh.metrics-publication',
            'await.instance-api-readiness',
            'hydrate.sample-apps',
            'normalize.permissions',
        ]);

        $guestCommands = collect($recorded)
            ->filter(
                fn (array $command): bool => (
                    in_array('exec', $command, true) && ! task7_is_global_ipv4_probe(array_slice($command, 6))
                ),
            )
            ->values()
            ->all();

        expect($guestCommands)
            ->toHaveCount(27)
            ->and(array_column(array_slice($guestCommands, 3, 3), 4))
            ->toBe([
                'lab:orbit-e2e-nck-123-aaaaaaaa-gateway',
                'lab:orbit-e2e-nck-123-aaaaaaaa-gateway',
                'lab:orbit-e2e-nck-123-aaaaaaaa-gateway',
            ]);

        expect(array_map(fn (array $command): array => array_slice($command, 6), array_slice($guestCommands, 0, 27)))
            ->toBe([
                ['/usr/local/bin/prepare-node.sh', 'align-identity'],
                ['/usr/local/bin/prepare-node.sh', 'align-identity'],
                ['/usr/local/bin/prepare-node.sh', 'align-identity'],
                ['/usr/local/bin/converge-gateway.sh', 'prerequisites'],
                ['/usr/local/bin/converge-gateway.sh', 'bootstrap', '192.0.2.10'],
                ['ssh-keygen', '-y', '-f', '/home/orbit/.orbit/ssh/id_ed25519'],
                ['/usr/local/bin/prepare-node.sh', 'gateway-authorize', task7_gateway_public_key()],
                ['/usr/local/bin/prepare-node.sh', 'gateway-authorize', task7_gateway_public_key()],
                ['/usr/local/bin/retarget-vpn.sh', '192.0.2.10'],
                ['/usr/local/bin/retarget-vpn.sh', '192.0.2.10'],
                ['uname', '-m'],
                ['uname', '-m'],
                ['/usr/local/bin/converge-app-dev.sh', 'app-dev', '192.0.2.11', 'x86_64'],
                [
                    '/usr/local/bin/converge-app-prod-internal-tls.sh',
                    'app-prod',
                    '192.0.2.12',
                    'aarch64',
                ],
                ['/usr/local/bin/converge-sample-app.sh', 'grant-operator', 'app-dev', 'gateway'],
                ['/usr/local/bin/converge-sample-app.sh', 'configure-cli', '10.44.0.1'],
                [
                    '/usr/local/bin/converge-sample-app.sh',
                    'create-resources',
                    'app-dev',
                    'app-prod',
                    str_repeat('b', 40),
                ],
                ['/usr/local/bin/converge-sample-app.sh', 'metrics', 'app-dev'],
                ['/usr/local/bin/converge-sample-app.sh', 'internal-tls'],
                ['/usr/local/bin/converge-sample-app.sh', 'reproject'],
                ['/usr/local/bin/converge-sample-app.sh', 'metrics-publication', 'app-dev'],
                ['/usr/local/bin/converge-sample-app.sh', 'instance-api-readiness'],
                ['/usr/local/bin/converge-sample-app.sh', 'hydrate', str_repeat('b', 40), 'app-dev'],
                ['/usr/local/bin/converge-sample-app.sh', 'hydrate', str_repeat('b', 40), 'app-prod'],
                ['/usr/local/bin/prepare-node.sh', 'permissions'],
                ['/usr/local/bin/prepare-node.sh', 'permissions'],
                ['/usr/local/bin/prepare-node.sh', 'permissions'],
            ]);

        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('init', $process->command, true)
            ),
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('create', $process->command, true)
            ),
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('start', $process->command, true)
            ),
        );
    });

    it('converges logical roles through physical cold recipe Nodes and normalizes every Node', function () {
        $target = TopologyTarget::disposableCold(
            'ORB-106',
            attemptId(),
            TopologyRecipe::coldAcceptance(),
        );
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded, $target): ProcessResult {
            return task7_process_result($process, $recorded, target: $target);
        });

        new TopologyConverger(task7_host())->converge(
            $target,
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        );

        $guestCommands = collect($recorded)->filter(
            fn (array $command): bool => in_array('exec', $command, true),
        );
        $identityInstances = $guestCommands
            ->filter(
                fn (array $command): bool => (
                    array_slice($command, 6) === [
                        '/usr/local/bin/prepare-node.sh',
                        'align-identity',
                    ]
                ),
            )
            ->pluck(4)
            ->values()
            ->all();
        $permissionInstances = $guestCommands
            ->filter(
                fn (array $command): bool => (
                    array_slice($command, 6) === [
                        '/usr/local/bin/prepare-node.sh',
                        'permissions',
                    ]
                ),
            )
            ->pluck(4)
            ->values()
            ->all();
        $arguments = $guestCommands->map(fn (array $command): array => array_slice($command, 6))->all();
        $expectedInstances = array_map(
            fn (string $node): string => 'lab:'.$target->instance($node),
            $target->recipe->nodeKeys(),
        );

        expect($identityInstances)
            ->toBe($expectedInstances)
            ->and($permissionInstances)
            ->toBe($expectedInstances)
            ->and($arguments)
            ->toContain(
                ['/usr/local/bin/converge-app-dev.sh', 'operator', '192.0.2.11', 'x86_64'],
                ['/usr/local/bin/converge-sample-app.sh', 'grant-operator', 'operator', 'gateway'],
                [
                    '/usr/local/bin/converge-sample-app.sh',
                    'create-resources',
                    'operator',
                    'app-prod',
                    str_repeat('b', 40),
                ],
                ['/usr/local/bin/converge-sample-app.sh', 'metrics', 'operator'],
                ['/usr/local/bin/converge-sample-app.sh', 'metrics-publication', 'operator'],
            );
    });

    it('hydrates only the validated typed development checkout', function (): void {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded): ProcessResult {
            return task7_process_result($process, $recorded, typed: true);
        });

        new TopologyConverger(task7_host())->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        );

        $arguments = collect($recorded)
            ->filter(fn (array $command): bool => in_array('exec', $command, true))
            ->map(fn (array $command): array => array_slice($command, 6))
            ->values()
            ->all();

        expect($arguments)
            ->toContain([
                '/usr/local/bin/converge-sample-app.sh',
                'hydrate',
                str_repeat('b', 40),
                'app-dev',
                '/srv/orbit/apps/laravel-typed/e2e-dev',
            ])
            ->not->toContain(
                ['/usr/local/bin/converge-sample-app.sh', 'internal-tls'],
                ['/usr/local/bin/converge-sample-app.sh', 'hydrate', str_repeat('b', 40), 'app-prod'],
            );
    });

    it('retries transient instance API readiness before invoking each hydration exactly once', function (bool $typed): void {
        $recorded = [];
        $readinessAttempts = 0;
        Process::fake(function (PendingProcess $process) use (&$recorded, &$readinessAttempts, $typed): ProcessResult {
            $command = $process->command;
            assert(is_array($command));
            if (
                in_array('/usr/local/bin/converge-sample-app.sh', $command, true)
                && in_array('instance-api-readiness', $command, true)
            ) {
                $readinessAttempts++;
                if ($readinessAttempts === 1) {
                    $recorded[] = $command;

                    return Process::result('', 'Gateway unavailable.', 1);
                }
            }

            return task7_process_result($process, $recorded, typed: $typed);
        });

        new TopologyConverger(task7_host(), instanceApiReadinessRetryDelayMicroseconds: 0)->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        );

        $sampleActions = collect($recorded)
            ->filter(fn (array $command): bool => in_array('/usr/local/bin/converge-sample-app.sh', $command, true))
            ->map(function (array $command): string {
                $script = array_search('/usr/local/bin/converge-sample-app.sh', $command, true);
                assert(is_int($script));

                return $command[$script + 1];
            })
            ->countBy()
            ->all();

        expect($sampleActions)->toMatchArray(array_filter(
            [
                'grant-operator' => 1,
                'configure-cli' => 1,
                'create-resources' => 1,
                'metrics' => 1,
                'internal-tls' => $typed ? null : 1,
                'reproject' => 1,
                'metrics-publication' => 1,
                'instance-api-readiness' => 2,
                'hydrate' => $typed ? 1 : 2,
            ],
            is_int(...),
        ));

        $actions = collect($recorded)
            ->filter(fn (array $command): bool => in_array('/usr/local/bin/converge-sample-app.sh', $command, true))
            ->map(function (array $command): string {
                $script = array_search('/usr/local/bin/converge-sample-app.sh', $command, true);
                assert(is_int($script));

                return $command[$script + 1];
            })
            ->values();

        expect($actions->search('hydrate', strict: true))
            ->toBeGreaterThan($actions->search('instance-api-readiness', strict: true));
    })->with([
        'legacy hydration' => false,
        'typed hydration' => true,
    ]);

    it('fails persistent instance API readiness before hydration with bounded diagnostics', function (int $exitCode): void {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded, $exitCode): ProcessResult {
            $command = $process->command;
            assert(is_array($command));
            if (
                in_array('/usr/local/bin/converge-sample-app.sh', $command, true)
                && in_array('instance-api-readiness', $command, true)
            ) {
                $recorded[] = $command;

                return Process::result('', 'instance:list --json failed.', $exitCode);
            }

            return task7_process_result($process, $recorded);
        });

        expect(fn () => new TopologyConverger(
            task7_host(),
            instanceApiReadinessRetryDelayMicroseconds: 0,
        )->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(
                RuntimeException::class,
                'Guest convergence readiness action converge-sample-app.sh instance-api-readiness failed '
                .'on orbit-e2e-nck-123-aaaaaaaa-app-dev after 5 attempts; probe instance:list --json failed '
                ."on attempt 5 with exit code {$exitCode}.",
            );

        expect(collect($recorded)->filter(
            fn (array $command): bool => (
                in_array('/usr/local/bin/converge-sample-app.sh', $command, true)
                && in_array('instance-api-readiness', $command, true)
            ),
        ))
            ->toHaveCount(5)
            ->and(collect($recorded)->filter(
                fn (array $command): bool => (
                    in_array('/usr/local/bin/converge-sample-app.sh', $command, true)
                    && in_array('hydrate', $command, true)
                ),
            ))
            ->toHaveCount(0);
    })->with([
        'unavailable instance API' => 1,
        'malformed instance envelope' => 65,
    ]);

    it('does not retry the hydration invocation after readiness succeeds', function (int $exitCode): void {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded, $exitCode): ProcessResult {
            $command = $process->command;
            assert(is_array($command));
            if (
                in_array('/usr/local/bin/converge-sample-app.sh', $command, true)
                && in_array('hydrate', $command, true)
            ) {
                $recorded[] = $command;

                return Process::result('', '', $exitCode);
            }

            return task7_process_result($process, $recorded, typed: true);
        });

        expect(fn () => new TopologyConverger(
            task7_host(),
            instanceApiReadinessRetryDelayMicroseconds: 0,
        )->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(
                RuntimeException::class,
                'Guest convergence script converge-sample-app.sh failed on '
                ."orbit-e2e-nck-123-aaaaaaaa-app-dev with exit code {$exitCode}.",
            );

        expect(collect($recorded)->filter(
            fn (array $command): bool => (
                in_array('/usr/local/bin/converge-sample-app.sh', $command, true) && in_array('hydrate', $command, true)
            ),
        ))->toHaveCount(1);
    })->with([
        'semantic typed state' => 65,
        'persistent preflight CLI failure' => 69,
        'reserved preflight status' => 75,
        'remapped post-readiness command' => 1,
    ]);

    it('fails before mutation when a required network is absent', function () {
        Process::fake(['*' => Process::result('[]')]);

        expect(fn () => new TopologyConverger(task7_host())->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(RuntimeException::class, 'network oe-0799eee4eaf4 does not exist');

        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('start', $process->command, true)
            ),
        );
    });

    it('preflights ownership of every resource before any mutation', function (string $foreignResource) {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded, $foreignResource) {
            $command = $process->command;
            assert(is_array($command));
            $recorded[] = $command;

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-0799eee4eaf4',
                    'config' => [
                        'user.orbit.e2e.owner' => $foreignResource === 'network' ? 'foreign' : 'orbit-e2e',
                        'ipv4.address' => '10.232.2.1/24',
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            $target = $command[count($command) - 2];
            if ($target === 'lab:') {
                return Process::result(json_encode(array_map(
                    static fn (string $role): array => json_decode(
                        task7_vm(
                            "orbit-e2e-nck-123-aaaaaaaa-{$role}",
                            $foreignResource === $role ? 'foreign' : 'orbit-e2e',
                        ),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['gateway', 'app-dev', 'app-prod'],
                ), JSON_THROW_ON_ERROR));
            }
            $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;
            $owner = $foreignResource === 'app-prod' && str_ends_with($name, '-app-prod') ? 'foreign' : 'orbit-e2e';

            return Process::result(task7_vm($name, $owner));
        });

        expect(fn () => new TopologyConverger(task7_host())->converge(
            featureTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        expect(collect($recorded)->filter(
            fn (array $command): bool => in_array('start', $command, true) || in_array('exec', $command, true),
        ))->toBeEmpty();
    })->with(['network', 'app-prod']);
});
