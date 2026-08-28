<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
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
    /** @param array<string, string> $ownershipMetadata */
    public function __construct(
        private IncusHost $host,
        private array $ownershipMetadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
    ) {
        if ($ownershipMetadata === []) {
            throw new RuntimeException('Incus convergence ownership metadata cannot be empty.');
        }

        foreach ($ownershipMetadata as $key => $value) {
            if (! str_starts_with($key, 'user.orbit.e2e.') || $value === '') {
                throw new RuntimeException('Incus convergence ownership metadata is invalid.');
            }
        }
    }

    public function converge(TopologyTarget $target, SourceState $source, LaravelRelease $laravel): ConvergenceReport
    {
        $instances = [];

        $network = $this->host->network($target->network());

        if ($network === null) {
            throw new RuntimeException("Incus network {$target->network()} does not exist.");
        }

        $resources = [$target->network() => $network->metadata];

        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
            $instance = $this->host->instance($instances[$role]);

            if ($instance === null) {
                throw new RuntimeException("Incus instance {$instances[$role]} does not exist.");
            }

            $resources[$instances[$role]] = $instance->metadata;
        }

        foreach ($resources as $resource => $metadata) {
            foreach ($this->ownershipMetadata as $key => $value) {
                if (($metadata[$key] ?? null) !== $value) {
                    throw new RuntimeException("Incus resource {$resource} ownership metadata does not match.");
                }
            }
        }

        $steps = ['validate.prerequisites' => true];

        foreach ($instances as $instance) {
            $this->host->start($instance);
        }

        $steps['start.instances'] = true;

        foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
            $this->run($instances[$role], 'prepare-node.sh', ['checkout', $source->guestSha, $role]);
        }

        $steps['prepare.checkouts'] = true;
        $this->run($instances['gateway'], 'converge-gateway.sh', ['hydrate', $source->guestSha]);
        $steps['hydrate.gateway'] = true;
        $this->run($instances['gateway'], 'converge-gateway.sh', ['bootstrap', $instances['gateway']]);
        $steps['bootstrap.gateway'] = true;
        $this->run(
            $instances['gateway'],
            'prepare-node.sh',
            ['ssh-pins', $instances['app-dev'], $instances['app-prod']],
        );
        $steps['pin.ssh-hosts'] = true;
        $this->run($instances['gateway'], 'converge-app-dev.sh', [$instances['app-dev']]);
        $steps['provision.app-dev'] = true;
        $this->run($instances['gateway'], 'converge-app-prod-internal-tls.sh', [$instances['app-prod']]);
        $steps['provision.app-prod'] = true;
        $this->run($instances['app-dev'], 'converge-sample-app.sh', ['configure-cli', $instances['gateway']]);
        $steps['configure.app-dev-cli'] = true;
        $this->run($instances['app-dev'], 'converge-sample-app.sh', [
            'create-resources',
            $instances['app-dev'],
            $instances['app-prod'],
            $laravel->commit,
        ]);
        $steps['create.sample-resources'] = true;

        foreach (['app-dev', 'app-prod'] as $role) {
            $this->run($instances[$role], 'converge-sample-app.sh', ['hydrate', $laravel->commit, $role]);
        }

        $steps['hydrate.sample-apps'] = true;

        foreach ($instances as $instance) {
            $this->run($instance, 'prepare-node.sh', ['permissions']);
        }

        $steps['normalize.permissions'] = true;

        return ConvergenceReport::successful($steps);
    }

    /** @param list<string> $arguments */
    private function run(string $instance, string $script, array $arguments): void
    {
        $result = $this->host->exec(
            $instance,
            new GuestCommand(['/usr/local/bin/'.$script, ...$arguments], 900),
        );

        if (! $result->successful()) {
            throw new RuntimeException("Guest convergence script {$script} failed on {$instance}.");
        }
    }
}
