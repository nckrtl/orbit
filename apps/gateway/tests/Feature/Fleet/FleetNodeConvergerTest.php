<?php

declare(strict_types=1);

use App\Data\Fleet\DesiredAgentData;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\FleetNodeConverger;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use Tests\Support\Fleet\FakeCliReleaseCatalog;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;

final class FleetConvergerProbe implements NodeReachabilityProbe
{
    /** @param bool|list<bool> $reachable  One answer for every probe, or one per probe in order. */
    public function __construct(public bool|array $reachable = true) {}

    public function degradation(Node $node): ?ExporterDegradationReason
    {
        $reachable = is_array($this->reachable) ? (array_shift($this->reachable) ?? true) : $this->reachable;

        return $reachable ? null : ExporterDegradationReason::Unreachable;
    }
}

final class FleetConvergerCli implements NodeCliInstaller
{
    public int $calls = 0;

    public function __construct(public string $outcome = NodeCliInstallation::Present, public ?ResourceOperationException $failure = null, public int $failures = PHP_INT_MAX) {}

    public string $inspected = 'present';

    public function inspect(Node $node): string
    {
        return $this->inspected;
    }

    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
    {
        $this->calls++;

        if ($this->failure instanceof ResourceOperationException && $this->failures-- > 0) {
            throw $this->failure;
        }

        return new NodeCliInstallation($this->outcome, false, $this->outcome === NodeCliInstallation::Installed ? $release->version : null);
    }
}

final class FleetConvergerVerification implements FleetNodeVerification
{
    public function __construct(public bool $passes = true) {}

    public function baseline(Node $node): array
    {
        return $this->baselineIssues;
    }

    /** @var list<list<string>> */
    public array $tolerated = [];

    /** @var list<string> */
    public array $baselineIssues = [];

    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        $this->tolerated[] = $tolerated;

        return [
            'passed' => $this->passes,
            'new_issues' => $this->passes ? [] : [['code' => 'node.agent_inactive', 'resource_type' => 'node', 'resource_id' => $node->id, 'summary' => 'inactive']],
            'preexisting_issues' => [],
            'agent_version' => $pinnedAgentVersion,
            'presence' => 'matched',
        ];
    }
}

function fleetDesiredState(): DesiredFleetStateData
{
    return new DesiredFleetStateData(
        FleetFixtures::Commit,
        new FakeCliReleaseCatalog()->find(FleetFixtures::Commit, new CliReleaseName(4681)),
        DesiredAgentData::fromFootprint(),
    );
}

/** @param array<string, mixed> $options */
function fleetConverger(ScriptedSshExecutor $ssh, array $options = []): FleetNodeConverger
{
    return new FleetNodeConverger(
        $options['probe'] ?? new FleetConvergerProbe,
        new NodeRoleConvergeLock(app(NodeLocks::class), waitSeconds: 0),
        $options['cli'] ?? new FleetConvergerCli,
        FleetTestSsh::shell($ssh),
        new NodeFootprint($options['artifacts'] ?? [new FakeFootprintArtifact('caddy', 'c1', changes: false)]),
        $options['verification'] ?? new FleetConvergerVerification,
        new NodeUpdateLock(FleetTestSsh::shell($ssh)),
    );
}

function selfUpdateReport(string $outcome, ?array $error = null): string
{
    return json_encode([
        'gateway' => 'gateway',
        'commit' => FleetFixtures::Commit,
        'outcome' => $outcome,
        'steps' => [
            ['step' => 'agent', 'outcome' => 'unchanged', 'reason' => null, 'after' => ['version' => '0.3.0', 'sha256' => str_repeat('a', 64)], 'error' => null],
            ['step' => 'cli', 'outcome' => $error === null ? $outcome : 'failed', 'reason' => null, 'after' => ['version' => '0.4681.0', 'sha256' => str_repeat('c', 64)], 'error' => $error],
        ],
        'request_id' => 'r',
    ], JSON_THROW_ON_ERROR);
}

describe('fleet Node converge', function (): void {
    it('runs the CLI install, self-update, footprint, and verify in that order', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('updated')));
        $cli = new FleetConvergerCli;

        $result = fleetConverger($ssh, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Converged)
            ->and($cli->calls)->toBe(1)
            ->and(array_values(array_filter($ssh->lines(), static fn (string $line): bool => ! str_contains($line, 'orbit-update-lock'))))->toBe(['sudo /usr/local/bin/orbit self-update --json'])
            ->and(count(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, 'orbit-update-lock'))))->toBe(4)
            ->and($result->cliVersion)->toBe('0.4681.0')
            ->and($result->evidence['footprint']['artifacts'])->toBe(['caddy' => 'unchanged'])
            ->and($result->footprintDigest)->toHaveLength(64);
    });

    it('reports unchanged when no step changed anything', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

        expect(fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)->toBe(FleetNodeOutcome::Unchanged);
    });

    it('counts a fresh CLI install as a change', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

        expect(fleetConverger($ssh, ['cli' => new FleetConvergerCli(NodeCliInstallation::Installed)])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)
            ->toBe(FleetNodeOutcome::Converged);
    });

    it('marks a Node that SSH cannot reach unreachable and runs nothing', function (): void {
        $ssh = new ScriptedSshExecutor;
        $cli = new FleetConvergerCli;

        $result = fleetConverger($ssh, ['probe' => new FleetConvergerProbe(false), 'cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unreachable)
            ->and($cli->calls)->toBe(0)
            ->and($ssh->commands)->toBe([]);
    });

    it('defers a Node whose role lock another operation holds', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $held = app(NodeLocks::class)->lock('node-role:id:'.$node->id, 60);
        $held->get();

        $result = fleetConverger(new ScriptedSshExecutor)->converge($node, fleetDesiredState());
        $held->release();

        expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
            ->and($result->errorCode)->toBe('node_role.node_busy');
    });

    it('fails at the self-update step with its exit code and output', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::fail(1, selfUpdateReport('failed', ['code' => 'self_update.checksum_mismatch', 'message' => 'mismatch']), 'bad checksum'));
        $footprint = new FakeFootprintArtifact('caddy', 'c1');

        $result = fleetConverger($ssh, ['artifacts' => [$footprint]])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('self-update')
            ->and($result->errorCode)->toBe('self_update.checksum_mismatch')
            ->and($result->evidence['details']['exit_code'])->toBe(1)
            ->and($result->evidence['details']['stderr'])->toBe('bad checksum')
            ->and($footprint->applied)->toBe([]);
    });

    it('fails at the CLI step and runs nothing after it', function (): void {
        $ssh = new ScriptedSshExecutor;

        $result = fleetConverger($ssh, ['cli' => new FleetConvergerCli(failure: new ResourceOperationException('cli.checksum_mismatch', 'mismatch', 502))])
            ->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('cli')
            ->and($result->errorCode)->toBe('cli.checksum_mismatch')
            ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, 'self-update --json')))->toBe([]);
    });

    it('fails at the footprint step with the artifact', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

        $result = fleetConverger($ssh, ['artifacts' => [new FakeFootprintArtifact('caddy', 'c1', fails: true)]])
            ->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->step)->toBe('footprint')
            ->and($result->evidence['details']['artifact'])->toBe('caddy');
    });

    it('fails when the verify finds a new Doctor issue', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('updated')));

        $result = fleetConverger($ssh, ['verification' => new FleetConvergerVerification(false)])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('verify')
            ->and($result->errorCode)->toBe('fleet.verify_failed')
            ->and($result->message)->toBe('Doctor reports node.agent_inactive after the rollout.');
    });
});

it('waits without failing when self-update is pending or incomplete', function (string $outcome, string $code): void {
    $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport($outcome)));
    $footprint = new FakeFootprintArtifact('caddy', 'c1');

    $result = fleetConverger($ssh, ['artifacts' => [$footprint]])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Waiting)
        ->and($result->errorCode)->toBe($code)
        ->and($result->outcome->awaitsCatchUp())->toBeTrue()
        ->and($footprint->applied)->toBe([]);
})->with([
    'pending' => ['pending', 'fleet.release_pending'],
    'incomplete' => ['incomplete', 'fleet.self_update_incomplete'],
]);

it('defers a Node whose self-update lock is busy', function (): void {
    $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::fail(1, json_encode(['status' => 409, 'error' => ['code' => 'self_update.busy', 'message' => 'busy']], JSON_THROW_ON_ERROR)));

    $result = fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
        ->and($result->errorCode)->toBe('self_update.busy');
});

it('fails with the envelope error code when self-update fails before any step', function (): void {
    $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::fail(1, json_encode(['status' => 500, 'error' => ['code' => 'self_update.lock_failed', 'message' => 'lock']], JSON_THROW_ON_ERROR)));

    $result = fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
        ->and($result->errorCode)->toBe('self_update.lock_failed');
});

it('defers a Node whose update lock a self-update keeps', function (): void {
    $ssh = new ScriptedSshExecutor()->on('/orbit-update-lock-[0-9a-f]+ \/run\/lock\/orbit-self-update\.lock 300 1800$/', ScriptedSshExecutor::fail(3));

    $result = fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
        ->and($result->errorCode)->toBe('node.update_busy')
        ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, 'self-update --json')))->toBe([]);
});

describe('fleet Node converge resilience', function (): void {
    it('marks a Node unreachable when self-update loses SSH and the Node no longer answers', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::fail(255, '', 'Connection closed'));

        $result = fleetConverger($ssh, ['probe' => new FleetConvergerProbe([true, false])])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unreachable)
            ->and($result->step)->toBe('self-update');
    });

    it('retries self-update once when it loses SSH and the Node still answers', function (): void {
        $runs = 0;
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', static function () use (&$runs) {
            return ++$runs === 1 ? ScriptedSshExecutor::fail(255, '', 'Connection closed') : ScriptedSshExecutor::ok(selfUpdateReport('unchanged'));
        });

        $result = fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($runs)->toBe(2);
    });

    it('does not retry a definite self-update failure', function (): void {
        $runs = 0;
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', static function () use (&$runs) {
            $runs++;

            return ScriptedSshExecutor::fail(1, selfUpdateReport('failed', ['code' => 'agent.unhealthy', 'message' => 'down']));
        });

        expect(fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->errorCode)->toBe('agent.unhealthy')
            ->and($runs)->toBe(1);
    });

    it('retries a failed CLI install once', function (): void {
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));
        $cli = new FleetConvergerCli(failure: new ResourceOperationException('cli.download_failed', 'reset', 502), failures: 1);

        expect(fleetConverger($ssh, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($cli->calls)->toBe(2);
    });

    it('defers a Node that a busy lock blocks', function (string $code): void {
        $ssh = new ScriptedSshExecutor;
        $cli = new FleetConvergerCli(failure: new ResourceOperationException($code, 'busy', 409));

        $result = fleetConverger($ssh, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
            ->and($result->errorCode)->toBe($code)
            ->and($cli->calls)->toBe(1);
    })->with(['agent.converge_busy', 'agent.converge_lock_lost', 'node.lock_lost', 'node.update_busy']);

    it('leaves a Node with a foreign CLI out of the rollout instead of failing it', function (): void {
        $node = FleetFixtures::node('beast', [RoleName::AppDev]);
        $cli = new FleetConvergerCli(failure: new ResourceOperationException('cli.foreign_binary', 'wrapper', 409));

        $result = fleetConverger(new ScriptedSshExecutor, ['cli' => $cli])->converge($node, fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Skipped)
            ->and($result->errorCode)->toBe('cli.foreign_binary')
            ->and(new NodeCliState()->isForeign($node))->toBeTrue()
            ->and($cli->calls)->toBe(1);
    });

    it('re-applies the artifact that repairs an owned issue the baseline shows, though its digest matches', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        new NodeFootprint([$agent, $caddy])->converge($node);
        $agent->applied = $caddy->applied = [];
        $verification = new FleetConvergerVerification;
        $verification->baselineIssues = ['node.agent_inactive:node:'.$node->id, 'node.disk_low:node:'.$node->id];
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

        fleetConverger($ssh, ['artifacts' => [$agent, $caddy], 'verification' => $verification])->converge($node, fleetDesiredState());

        expect($agent->applied)->toBe(['dev'])
            ->and($caddy->applied)->toBe([]);
    });

    it('lets a Gateway rollback downgrade the CLI to exactly the desired version', function (bool $allowed, FleetNodeOutcome $outcome): void {
        $ssh = new ScriptedSshExecutor()
            ->on('/--allow-downgrade-to=0\.4681\.0$/', ScriptedSshExecutor::ok(selfUpdateReport('updated')))
            ->on('/self-update --json$/', ScriptedSshExecutor::fail(1, selfUpdateReport('failed', ['code' => 'self_update.downgrade_refused', 'message' => 'older'])));

        $result = fleetConverger($ssh)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState(), $allowed);

        expect($result->outcome)->toBe($outcome)
            ->and(count(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, '--allow-downgrade-to=0.4681.0'))))->toBe($allowed ? 1 : 0);
    })->with([
        'a verified release' => [true, FleetNodeOutcome::Converged],
        'anything else' => [false, FleetNodeOutcome::Failed],
    ]);

    it('tolerates Caddy drift from a Caddyfile a user\'s site made unbuildable', function (): void {
        $verification = new FleetConvergerVerification;
        $caddy = new class implements NodeFootprintArtifact
        {
            public function name(): string
            {
                return 'caddy';
            }

            public function applies(Node $node): bool
            {
                return true;
            }

            public function digest(Node $node): string
            {
                return 'code-v1';
            }

            public string $reason = 'caddy_render_refused';

            public function apply(Node $node): ?bool
            {
                throw new FootprintArtifactSkipped($this->reason, 'A site is invalid.');
            }
        };
        $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

        $result = fleetConverger($ssh, ['artifacts' => [$caddy], 'verification' => $verification])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($result->evidence['footprint']['artifacts'])->toBe(['caddy' => 'skipped'])
            ->and($result->evidence['footprint']['skipped']['caddy']['reason'])->toBe('caddy_render_refused')
            ->and($verification->tolerated)->toBe([['role.caddy_build_drift']]);

        // A refused validation on the first Node of a rollout is more likely Orbit's own template: it fails.
        $caddy->reason = 'caddy_validate_refused';
        $first = fleetConverger($ssh, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('first', [RoleName::AppDev]), fleetDesiredState(), firstVisit: true);
        $later = fleetConverger($ssh, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('later', [RoleName::AppDev]), fleetDesiredState(), firstVisit: false);
        $render = (static function () use ($caddy, $ssh) {
            $caddy->reason = 'caddy_render_refused';

            return fleetConverger($ssh, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('render', [RoleName::AppDev]), fleetDesiredState(), firstVisit: true);
        })();

        expect($first->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($first->errorCode)->toBe('node.footprint_caddy_failed')
            ->and($later->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($render->outcome)->toBe(FleetNodeOutcome::Unchanged);
    });
});

it('looks at a foreign CLI again only when the Node answers', function (): void {
    $node = FleetFixtures::node('beast', [RoleName::AppDev]);
    new NodeCliState()->markForeign($node);
    $cli = new FleetConvergerCli;
    $cli->inspected = 'missing';

    expect(fleetConverger(new ScriptedSshExecutor, ['cli' => $cli, 'probe' => new FleetConvergerProbe(false)])->recheckCli($node))->toBeFalse()
        ->and(new NodeCliState()->isForeign($node))->toBeTrue()
        ->and(fleetConverger(new ScriptedSshExecutor, ['cli' => $cli])->recheckCli($node))->toBeTrue()
        ->and(new NodeCliState()->isForeign($node))->toBeFalse();
});

it('keeps a refused validation skipped on the first Node when Caddy only repaired live drift', function (): void {
    $node = FleetFixtures::node('dev', [RoleName::AppDev]);
    App\Models\NodeFootprint::query()->create(['node_id' => $node->id, 'digest' => str_repeat('d', 64), 'artifacts' => ['caddy' => 'code-v1'], 'converged_at' => now()]);
    $caddy = new class implements NodeFootprintArtifact
    {
        public int $applied = 0;

        public function name(): string
        {
            return 'caddy';
        }

        public function applies(Node $node): bool
        {
            return true;
        }

        public function digest(Node $node): string
        {
            return 'code-v1';
        }

        public function apply(Node $node): ?bool
        {
            $this->applied++;

            throw new FootprintArtifactSkipped('caddy_validate_refused', 'A user site is invalid.');
        }
    };
    $verification = new FleetConvergerVerification;
    $verification->baselineIssues = ['role.caddy_build_drift:node_role:1'];
    $ssh = new ScriptedSshExecutor()->on('/self-update --json$/', ScriptedSshExecutor::ok(selfUpdateReport('unchanged')));

    $result = fleetConverger($ssh, ['artifacts' => [$caddy], 'verification' => $verification])->converge($node, fleetDesiredState(), firstVisit: true);

    expect($caddy->applied)->toBe(1)
        ->and($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
        ->and($result->evidence['footprint']['skipped']['caddy']['repair'])->toBeTrue();
});
