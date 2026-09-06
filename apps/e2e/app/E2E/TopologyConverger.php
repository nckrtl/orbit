<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use JsonException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity The convergence contract keeps one explicit ordered operation sequence.
 * @mago-expect lint:kan-defect Each guarded phase must remain visible and fail closed.
 */
final readonly class TopologyConverger
{
    private const int INSTANCE_API_READINESS_ATTEMPTS = 30;

    public function __construct(
        private IncusHost $host,
        private int $instanceApiReadinessRetryDelayMicroseconds = 1_000_000,
    ) {}

    public function converge(TopologyTarget $target, SourceState $source, LaravelRelease $laravel): ConvergenceReport
    {
        $nodes = array_combine(
            $target->recipe->nodeKeys(),
            array_map($target->instance(...), $target->recipe->nodeKeys()),
        );
        $instances = $nodes;
        $gatewayNode = $target->recipe->nodeForRole('gateway')->key;
        $appDevNode = $target->recipe->nodeForRole('app-dev')->key;
        $appProdNodes = $target->recipe->nodesForRole('app-prod');
        if ($appProdNodes === []) {
            throw new RuntimeException('The topology has no app-prod Node.');
        }
        $appProdNode = $appProdNodes[0]->key;

        $this->host->assertTopologyNetworkIdentity($nodes, $target->network(), $target);
        $addresses = $this->host->globalIpv4All($nodes);

        $steps = ['validate.prerequisites' => true];

        $identityCommands = [];
        foreach ($nodes as $node => $instance) {
            $identityCommands[$node] = [
                'instance' => $instance,
                'script' => 'prepare-node.sh',
                'arguments' => ['align-identity'],
            ];
        }
        $this->runAll($identityCommands);
        $steps['align.identity'] = true;

        $this->run($instances['gateway'], 'converge-gateway.sh', ['prerequisites']);
        $steps['prerequisites.gateway'] = true;
        $this->run($instances['gateway'], 'converge-gateway.sh', ['bootstrap', $addresses[$gatewayNode]]);
        $steps['bootstrap.gateway'] = true;
        $gatewayPublicKey = $this->gatewayPublicKey($instances['gateway']);
        $authorize = [
            $appDevNode => [
                'instance' => $instances[$appDevNode],
                'script' => 'prepare-node.sh',
                'arguments' => ['gateway-authorize', $gatewayPublicKey],
            ],
        ];
        foreach ($appProdNodes as $node) {
            $authorize[$node->key] = [
                'instance' => $instances[$node->key],
                'script' => 'prepare-node.sh',
                'arguments' => ['gateway-authorize', $gatewayPublicKey],
            ];
        }
        $this->runAll($authorize);
        $steps['authorize.gateway-ssh'] = true;
        $retarget = [];
        foreach ([$target->recipe->node($appDevNode), ...$appProdNodes] as $node) {
            $retarget[$node->key] = [
                'instance' => $instances[$node->key],
                'script' => 'retarget-vpn.sh',
                'arguments' => [$addresses[$gatewayNode]],
            ];
        }
        $this->runAll($retarget);
        $steps['retarget.vpn'] = true;
        $architectureNodes = [$appDevNode, ...array_map(static fn ($node): string => $node->key, $appProdNodes)];
        $architectures = $this->architectures(array_intersect_key($instances, array_flip($architectureNodes)));
        $appDevArchitecture = $architectures[$appDevNode] ?? null;
        if (! is_string($appDevArchitecture)) {
            throw new RuntimeException('Target node architectures are incomplete.');
        }
        // Both commands mutate the shared Gateway SQLite store. Keep this phase ordered.
        $this->runAll([
            'app-dev' => [
                'instance' => $instances['gateway'],
                'script' => 'converge-app-dev.sh',
                'arguments' => [$appDevNode, $addresses[$appDevNode], $appDevArchitecture],
            ],
        ]);
        foreach ($appProdNodes as $node) {
            $architecture = $architectures[$node->key] ?? null;
            if (! is_string($architecture)) {
                throw new RuntimeException('Target node architectures are incomplete.');
            }
            $this->runAll([
                $node->key => [
                    'instance' => $instances[$gatewayNode],
                    'script' => 'converge-app-prod-internal-tls.sh',
                    'arguments' => [
                        $node->key,
                        $addresses[$node->key],
                        $architecture,
                        $node->wireGuardAddress(),
                    ],
                ],
            ]);
        }
        $steps['provision.app-dev'] = true;
        $steps['provision.app-prod'] = true;
        $this->run(
            $instances['gateway'],
            'converge-sample-app.sh',
            [
                'grant-operator',
                $appDevNode,
                $gatewayNode,
            ],
        );
        $steps['authorize.app-dev-operator'] = true;
        $this->run($instances[$appDevNode], 'converge-sample-app.sh', ['configure-cli', '10.44.0.1']);
        $steps['configure.app-dev-cli'] = true;
        $sampleResources = $this->run($instances[$appDevNode], 'converge-sample-app.sh', [
            'create-resources',
            $appDevNode,
            $appProdNode,
            $laravel->commit,
        ]);
        $typedCheckoutPath = $this->typedCheckoutPath($sampleResources);
        $steps['create.sample-resources'] = true;
        $this->run($instances[$appDevNode], 'converge-sample-app.sh', ['metrics', $appDevNode]);
        $steps['converge.metrics'] = true;
        // Rolling refreshes restore snapshots and skip provisioning, so the
        // product must re-render every projection from the checked-out code.
        // The app-prod internal-TLS fragment lands inside the managed Caddy
        // layout first so the product publisher carries it forward.
        if ($typedCheckoutPath === null) {
            $this->run($instances[$appProdNode], 'converge-sample-app.sh', ['internal-tls']);
        }
        $this->run($instances[$appDevNode], 'converge-sample-app.sh', ['reproject']);
        $steps['reproject.product-state'] = true;
        $this->run($instances[$appDevNode], 'converge-sample-app.sh', ['metrics-publication', $appDevNode]);
        $steps['refresh.metrics-publication'] = true;
        $this->awaitInstanceApiReadiness($instances[$appDevNode]);
        $steps['await.instance-api-readiness'] = true;

        if ($typedCheckoutPath !== null) {
            $this->run($instances[$appDevNode], 'converge-sample-app.sh', [
                'hydrate',
                $laravel->commit,
                'app-dev',
                $typedCheckoutPath,
            ]);
        } else {
            $this->runAll([
                'app-dev' => [
                    'instance' => $instances[$appDevNode],
                    'script' => 'converge-sample-app.sh',
                    'arguments' => ['hydrate', $laravel->commit, 'app-dev'],
                ],
                'app-prod' => [
                    'instance' => $instances[$appProdNode],
                    'script' => 'converge-sample-app.sh',
                    'arguments' => ['hydrate', $laravel->commit, 'app-prod'],
                ],
            ]);
        }

        $steps['hydrate.sample-apps'] = true;

        $permissionCommands = [];
        foreach ($nodes as $node => $instance) {
            $permissionCommands[$node] = [
                'instance' => $instance,
                'script' => 'prepare-node.sh',
                'arguments' => ['permissions'],
            ];
        }
        $this->runAll($permissionCommands);

        $steps['normalize.permissions'] = true;

        return ConvergenceReport::successful($steps);
    }

    /** @param array<string, string> $instances @return array<string, string> */
    private function architectures(array $instances): array
    {
        $requests = [];
        foreach ($instances as $node => $instance) {
            $requests[$node] = ['instance' => $instance, 'command' => new GuestCommand(['uname', '-m'], 10)];
        }
        $results = $this->host->execAll($requests);
        $architectures = [];
        foreach ($instances as $node => $instance) {
            $result = $results[$node] ?? null;
            if (! $result instanceof GuestCommandResult) {
                throw new RuntimeException("Target node {$instance} returned no architecture result.");
            }
            $architecture = trim($result->stdout);
            if (! $result->successful() || preg_match('/\A(?:x86_64|aarch64)\z/', $architecture) !== 1) {
                throw new RuntimeException("Target node {$instance} reported an invalid architecture.");
            }
            $architectures[$node] = $architecture;
        }

        return $architectures;
    }

    /** @param array<string, array{instance:string,script:string,arguments:list<string>}> $commands */
    private function runAll(array $commands): void
    {
        $requests = [];
        foreach ($commands as $label => $command) {
            $requests[$label] = [
                'instance' => $command['instance'],
                'command' => new GuestCommand(['/usr/local/bin/'.$command['script'], ...$command['arguments']], 900),
            ];
        }
        $results = $this->host->execAll($requests);
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $script = $commands[$label]['script'];
                $instance = $commands[$label]['instance'];

                throw new RuntimeException(
                    "Guest convergence script {$script} failed on {$instance} "
                    ."with exit code {$result->exitCode}{$this->failureDetails($script, $result)}.",
                );
            }
        }
    }

    private function gatewayPublicKey(string $gateway): string
    {
        $result = $this->host->exec($gateway, new GuestCommand([
            'ssh-keygen',
            '-y',
            '-f',
            '/home/orbit/.orbit/ssh/id_ed25519',
        ], 10));
        if (! $result->successful()) {
            throw new RuntimeException('Failed to derive the Gateway SSH public key.');
        }

        $key = trim($result->stdout);
        if (preg_match('/\A(ssh-ed25519 [A-Za-z0-9+\/]+={0,2})(?: [^\r\n]+)?\z/D', $key, $matches) !== 1) {
            throw new RuntimeException('The Gateway SSH public key is invalid.');
        }

        return $matches[1];
    }

    /** @param list<string> $arguments */
    private function run(string $instance, string $script, array $arguments): GuestCommandResult
    {
        $result = $this->host->exec(
            $instance,
            new GuestCommand(['/usr/local/bin/'.$script, ...$arguments], 900),
        );

        if (! $result->successful()) {
            throw new RuntimeException(
                "Guest convergence script {$script} failed on {$instance} "
                ."with exit code {$result->exitCode}{$this->failureDetails($script, $result)}.",
            );
        }

        return $result;
    }

    private function awaitInstanceApiReadiness(string $instance): void
    {
        for ($attempt = 1; $attempt <= self::INSTANCE_API_READINESS_ATTEMPTS; $attempt++) {
            $result = $this->host->exec(
                $instance,
                new GuestCommand(['/usr/local/bin/converge-sample-app.sh', 'instance-api-readiness'], 30),
            );

            if ($result->successful()) {
                return;
            }

            if ($attempt === self::INSTANCE_API_READINESS_ATTEMPTS) {
                throw new RuntimeException(
                    'Guest convergence readiness action converge-sample-app.sh instance-api-readiness failed '
                    ."on {$instance} after "
                    .self::INSTANCE_API_READINESS_ATTEMPTS
                    ." attempts; probe instance:list --json failed on attempt {$attempt} "
                    ."with exit code {$result->exitCode}.",
                );
            }

            if ($this->instanceApiReadinessRetryDelayMicroseconds > 0) {
                usleep($this->instanceApiReadinessRetryDelayMicroseconds);
            }
        }
    }

    private function typedCheckoutPath(GuestCommandResult $result): ?string
    {
        if ($result->stdout === '') {
            return null;
        }

        try {
            $state = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Sample resource convergence returned malformed typed state.', 0, $exception);
        }

        if (
            ! is_array($state)
            || array_keys($state) !== ['shape', 'app_id', 'node_id', 'name', 'checkout_path', 'effective_root']
            || ($state['shape'] ?? null) !== 'app_instances'
            || ! is_int($state['app_id'] ?? null)
            || ! is_int($state['node_id'] ?? null)
            || ($state['name'] ?? null) !== 'e2e-dev'
            || ! is_string($state['checkout_path'] ?? null)
            || ! str_starts_with($state['checkout_path'], '/')
            || ($state['effective_root'] ?? null) !== 'public'
        ) {
            throw new RuntimeException('Sample resource convergence returned invalid typed state.');
        }

        return $state['checkout_path'];
    }

    private function failureDetails(string $script, GuestCommandResult $result): string
    {
        $pattern = match (true) {
            $script === 'converge-gateway.sh' && $result->exitCode === 71
                => '/(?:\A|\R)Gateway bootstrap failed at step \[([a-z0-9:-]+)\] with error \[([a-z0-9._-]+)\]\.(?:\R|\z)/D',
            in_array($script, ['converge-app-dev.sh', 'converge-app-prod-internal-tls.sh'], true)
                && $result->exitCode === 1
                => '/(?:\A|\R)Node provisioning failed at step \[([a-z0-9:-]+)\] with error \[([a-z0-9._-]+)\]\.(?:\R|\z)/D',
            default => null,
        };

        if (
            $pattern === null
            || preg_match($pattern, $result->stdout."\n".$result->stderr, $failure) !== 1
        ) {
            return '';
        }

        return " at step {$failure[1]} ({$failure[2]})";
    }
}
