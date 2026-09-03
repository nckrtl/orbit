<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use JsonException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity The convergence contract keeps one explicit ordered operation sequence.
 * @mago-expect lint:kan-defect Each guarded phase must remain visible and fail closed.
 */
final readonly class TopologyConverger
{
    private const int INSTANCE_API_READINESS_ATTEMPTS = 5;

    public function __construct(
        private IncusHost $host,
        private int $instanceApiReadinessRetryDelayMicroseconds = 1_000_000,
    ) {}

    public function converge(TopologyTarget $target, SourceState $source, LaravelRelease $laravel): ConvergenceReport
    {
        $instances = array_combine(
            TopologyProfile::ROLES,
            array_map($target->instance(...), TopologyProfile::ROLES),
        );

        $this->host->assertTopologyNetworkIdentity($instances, $target->network());
        $addresses = $this->host->globalIpv4All($instances);

        $steps = ['validate.prerequisites' => true];

        $identityCommands = [];
        foreach ($instances as $role => $instance) {
            $identityCommands[$role] = [
                'instance' => $instance,
                'script' => 'prepare-node.sh',
                'arguments' => ['align-identity'],
            ];
        }
        $this->runAll($identityCommands);
        $steps['align.identity'] = true;

        $this->run($instances['gateway'], 'converge-gateway.sh', ['prerequisites']);
        $steps['prerequisites.gateway'] = true;
        $this->run($instances['gateway'], 'converge-gateway.sh', ['bootstrap', $addresses['gateway']]);
        $steps['bootstrap.gateway'] = true;
        $gatewayPublicKey = $this->gatewayPublicKey($instances['gateway']);
        $this->runAll([
            'app-dev' => [
                'instance' => $instances['app-dev'],
                'script' => 'prepare-node.sh',
                'arguments' => ['gateway-authorize', $gatewayPublicKey],
            ],
            'app-prod' => [
                'instance' => $instances['app-prod'],
                'script' => 'prepare-node.sh',
                'arguments' => ['gateway-authorize', $gatewayPublicKey],
            ],
        ]);
        $steps['authorize.gateway-ssh'] = true;
        $this->runAll([
            'app-dev' => [
                'instance' => $instances['app-dev'],
                'script' => 'retarget-vpn.sh',
                'arguments' => [$addresses['gateway']],
            ],
            'app-prod' => [
                'instance' => $instances['app-prod'],
                'script' => 'retarget-vpn.sh',
                'arguments' => [$addresses['gateway']],
            ],
        ]);
        $steps['retarget.vpn'] = true;
        $architectures = $this->architectures($instances);
        $appDevArchitecture = $architectures['app-dev'] ?? null;
        $appProdArchitecture = $architectures['app-prod'] ?? null;
        if (! is_string($appDevArchitecture) || ! is_string($appProdArchitecture)) {
            throw new RuntimeException('Target node architectures are incomplete.');
        }
        // Both commands mutate the shared Gateway SQLite store. Keep this phase ordered.
        $this->runAll([
            'app-dev' => [
                'instance' => $instances['gateway'],
                'script' => 'converge-app-dev.sh',
                'arguments' => ['app-dev', $addresses['app-dev'], $appDevArchitecture],
            ],
        ]);
        $this->runAll([
            'app-prod' => [
                'instance' => $instances['gateway'],
                'script' => 'converge-app-prod-internal-tls.sh',
                'arguments' => ['app-prod', $addresses['app-prod'], $appProdArchitecture],
            ],
        ]);
        $steps['provision.app-dev'] = true;
        $steps['provision.app-prod'] = true;
        $this->run(
            $instances['gateway'],
            'converge-sample-app.sh',
            [
                'grant-operator',
                'app-dev',
                'gateway',
            ],
        );
        $steps['authorize.app-dev-operator'] = true;
        $this->run($instances['app-dev'], 'converge-sample-app.sh', ['configure-cli', '10.44.0.1']);
        $steps['configure.app-dev-cli'] = true;
        $sampleResources = $this->run($instances['app-dev'], 'converge-sample-app.sh', [
            'create-resources',
            'app-dev',
            'app-prod',
            $laravel->commit,
        ]);
        $typedCheckoutPath = $this->typedCheckoutPath($sampleResources);
        $steps['create.sample-resources'] = true;
        $this->run($instances['app-dev'], 'converge-sample-app.sh', ['metrics']);
        $steps['converge.metrics'] = true;
        // Rolling refreshes restore snapshots and skip provisioning, so the
        // product must re-render every projection from the checked-out code.
        // The app-prod internal-TLS fragment lands inside the managed Caddy
        // layout first so the product publisher carries it forward.
        if ($typedCheckoutPath === null) {
            $this->run($instances['app-prod'], 'converge-sample-app.sh', ['internal-tls']);
        }
        $this->run($instances['app-dev'], 'converge-sample-app.sh', ['reproject']);
        $steps['reproject.product-state'] = true;
        $this->run($instances['app-dev'], 'converge-sample-app.sh', ['metrics-publication']);
        $steps['refresh.metrics-publication'] = true;
        $this->awaitInstanceApiReadiness($instances['app-dev']);
        $steps['await.instance-api-readiness'] = true;

        if ($typedCheckoutPath !== null) {
            $this->run($instances['app-dev'], 'converge-sample-app.sh', [
                'hydrate',
                $laravel->commit,
                'app-dev',
                $typedCheckoutPath,
            ]);
        } else {
            $this->runAll([
                'app-dev' => [
                    'instance' => $instances['app-dev'],
                    'script' => 'converge-sample-app.sh',
                    'arguments' => ['hydrate', $laravel->commit, 'app-dev'],
                ],
                'app-prod' => [
                    'instance' => $instances['app-prod'],
                    'script' => 'converge-sample-app.sh',
                    'arguments' => ['hydrate', $laravel->commit, 'app-prod'],
                ],
            ]);
        }

        $steps['hydrate.sample-apps'] = true;

        $permissionCommands = [];
        foreach ($instances as $role => $instance) {
            $permissionCommands[$role] = [
                'instance' => $instance,
                'script' => 'prepare-node.sh',
                'arguments' => ['permissions'],
            ];
        }
        $this->runAll($permissionCommands);

        $steps['normalize.permissions'] = true;

        return ConvergenceReport::successful($steps);
    }

    /** @param array<string, string> $instances @return array{app-dev:string,app-prod:string} */
    private function architectures(array $instances): array
    {
        $results = $this->host->execAll([
            'app-dev' => ['instance' => $instances['app-dev'], 'command' => new GuestCommand(['uname', '-m'], 10)],
            'app-prod' => ['instance' => $instances['app-prod'], 'command' => new GuestCommand(['uname', '-m'], 10)],
        ]);
        $architectures = [];
        foreach (['app-dev', 'app-prod'] as $role) {
            $result = $results[$role] ?? null;
            if (! $result instanceof GuestCommandResult) {
                throw new RuntimeException("Target node {$instances[$role]} returned no architecture result.");
            }
            $architecture = trim($result->stdout);
            if (! $result->successful() || preg_match('/\A(?:x86_64|aarch64)\z/', $architecture) !== 1) {
                throw new RuntimeException("Target node {$instances[$role]} reported an invalid architecture.");
            }
            $architectures[$role] = $architecture;
        }

        return [
            'app-dev' => $architectures['app-dev'],
            'app-prod' => $architectures['app-prod'],
        ];
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
