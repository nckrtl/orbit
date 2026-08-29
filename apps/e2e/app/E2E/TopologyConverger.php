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
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity The convergence contract keeps one explicit ordered operation sequence.
 * @mago-expect lint:kan-defect Each guarded phase must remain visible and fail closed.
 */
final readonly class TopologyConverger
{
    public function __construct(
        private IncusHost $host,
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
        $this->run($instances['app-dev'], 'converge-sample-app.sh', [
            'create-resources',
            'app-dev',
            'app-prod',
            $laravel->commit,
        ]);
        $steps['create.sample-resources'] = true;

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
    private function run(string $instance, string $script, array $arguments): void
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
