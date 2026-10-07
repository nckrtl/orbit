<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\FpmPoolConnections;
use App\Infrastructure\Gateway\FpmScriptRequest;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Models\Node;
use Closure;
use Throwable;

/**
 * Hands the Gateway's long-running processes over to the release that is now current. It runs in that release, so
 * a release that changes what the Gateway renders applies it at once:
 *
 * 1. Caddy publishes the Node's Caddyfile only when it changed, with a graceful reload.
 * 2. PHP-FPM reloads only when the rendered pool differs from the live pool. Requests keep running otherwise.
 * 3. The hibernator and agent-view units are installed again; agent-view restarts on the new code.
 * 4. The scheduler finishes its running commands and starts on the new release ({@see GatewaySchedulerHandoff}).
 * 5. Document cleanup is reconciled and resumed ({@see GatewayCleanupHandoff}).
 *
 * @phpstan-import-type HandoffResult from \App\Domain\GatewayReleases\GatewayReleaseRuntime
 */
final readonly class GatewayRuntimeHandoff
{
    public const string LivePool = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    /** @var Closure(string): (string|false) */
    private Closure $readLivePool;

    /** @var Closure(string, string): string */
    private Closure $resetOpcache;

    /** @var Closure(): int */
    private Closure $fpmConnections;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(string): (string|false))|null  $readLivePool
     * @param  (Closure(string, string): string)|null  $resetOpcache  runs the reset script inside the pool with a query and returns its JSON
     * @param  (Closure(): int)|null  $fpmConnections  requests in flight in every pool of the PHP-FPM master, which share one OPcache
     * @param  (Closure(int): void)|null  $sleep  microseconds
     */
    public function __construct(
        private NodeCaddyBuilds $builds,
        private GatewayFpmConfigRenderer $fpmRenderer,
        private NativeGatewayFpmConverger $fpm,
        private ProtectedFileWriter $files,
        private RuntimeHibernatorConverger $hibernator,
        private AgentViewConverger $agentView,
        private GatewaySchedulerHandoff $scheduler,
        private GatewayCleanupHandoff $cleanup,
        private string $applicationPath,
        private string $orbitHome,
        private string $livePool = self::LivePool,
        ?Closure $readLivePool = null,
        ?Closure $resetOpcache = null,
        ?Closure $fpmConnections = null,
        ?Closure $sleep = null,
        private int $idleWaitSeconds = 60,
    ) {
        $this->readLivePool = $readLivePool ?? static fn (string $path): string|false => @file_get_contents($path);
        $this->resetOpcache = $resetOpcache ?? static fn (string $script, string $query): string => new FpmScriptRequest()->request($script, $query);
        $this->fpmConnections = $fpmConnections ?? new FpmPoolConnections(dirname($this->livePool))->count(...);
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Both phases, for an operator who runs the handoff by hand.
     *
     * @return HandoffResult
     *
     * @throws GatewayReleaseException
     */
    public function run(): array
    {
        return [...$this->serve(), ...$this->schedule()];
    }

    /**
     * What serves requests: Caddy, PHP-FPM, and the units. It runs before verify, so a broken release is
     * found, and switched back, without waiting for the scheduler.
     *
     * @return HandoffResult
     *
     * @throws GatewayReleaseException
     */
    public function serve(): array
    {
        $gateway = $this->gateway();
        $caddy = $this->caddy($gateway);
        $fpm = $this->fpm();
        $this->step('units', 'gateway.release_units_failed', function (): void {
            $this->hibernator->converge();
            $this->agentView->converge();
        });

        return [
            'caddy' => $caddy,
            'fpm' => $fpm,
            'agent_view' => 'restarted',
        ];
    }

    /**
     * What runs in the background, after verify, because each step can wait: the scheduler drain and restart, document
     * cleanup, and the OPcache reset.
     *
     * @return HandoffResult
     *
     * @throws GatewayReleaseException
     */
    public function schedule(): array
    {
        $gateway = $this->gateway();
        $generation = $this->cleanup->generation();
        $scheduler = $this->scheduler->handoff($gateway);
        $cleanup = $this->cleanup->resume($generation, true);

        return [
            'scheduler' => $scheduler['outcome'],
            'scheduler_unit' => $scheduler['unit'],
            'scheduler_drain' => $scheduler['drain'],
            'processes_restarted' => $scheduler['restarted'],
            'cleanup' => $cleanup['outcome'],
            'cleanup_error_code' => $cleanup['error_code'] ?? null,
            'cleanup_paused' => $cleanup['paused'],
            // Last, after verify: the reset may wait up to a minute for the pools to go idle.
            'opcache' => $this->opcache(),
        ];
    }

    private function gateway(): Node
    {
        $node = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query
                ->where('role', RoleName::Gateway)
                ->where('status', LifecycleStatus::Active))
            ->first();

        if (! $node instanceof Node) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_gateway_node_missing',
                message: 'No active Gateway Node exists to hand the runtime over on.',
                status: 500,
            );
        }

        return $node;
    }

    private function caddy(Node $gateway): string
    {
        try {
            return $this->builds->build($gateway)->value === 'published' ? 'reloaded' : 'unchanged';
        } catch (NodeCaddyBuildException $exception) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_caddy_failed',
                message: 'The Gateway Caddyfile could not be published: '.$exception->getMessage(),
                status: 500,
                previous: $exception,
                result: $exception->result(),
            );
        }
    }

    /** Reloads PHP-FPM only for a changed pool, because a reload ends the requests in flight. */
    private function fpm(): string
    {
        $rendered = $this->fpmRenderer->renderPool($this->applicationPath, $this->orbitHome);

        if (($this->readLivePool)($this->livePool) === $rendered) {
            return 'unchanged';
        }

        $this->step('fpm', 'gateway.release_fpm_failed', function () use ($rendered): void {
            $generated = rtrim($this->orbitHome, '/').'/generated/gateway/php-fpm-pool.conf';
            $this->files->put($generated, $rendered, 0o644);
            $this->fpm->converge($generated);
        });

        return 'reloaded';
    }

    /**
     * Resets OPcache, so the scripts of releases that no longer serve do not fill it. Release files never change in
     * place, so OPcache would never mark them wasted.
     *
     * OPcache restarts once no request uses the cache. A restart that stays pending for `opcache.force_restart_timeout`
     * (180 seconds) kills the workers that still serve one, and a Gateway request may run 600 seconds. All pools of the
     * PHP-FPM master share one OPcache, so the reset runs only while no pool serves a request, and the restart happens
     * with the next one. A request that starts in the milliseconds between that check and the reset, and runs longer
     * than 180 seconds, could still be killed. A master that never goes idle within the wait is reset by a later
     * release. The reset then checks that the restart is no longer pending. A failure never fails the release.
     *
     * @return array<string, mixed>
     */
    private function opcache(): array
    {
        $deadline = hrtime(true) + $this->idleWaitSeconds * 1_000_000_000;

        while (($this->fpmConnections)() > 0) {
            if (hrtime(true) >= $deadline) {
                return ['outcome' => 'deferred'];
            }

            ($this->sleep)(100_000);
        }

        $release = realpath($this->applicationPath);
        $script = ($release === false ? $this->applicationPath : $release).'/resources/fpm/opcache-reset.php';

        if (! is_file($script)) {
            // A release that predates the script, such as adoption's first one: this process's copy is identical.
            $script = base_path('resources/fpm/opcache-reset.php');
        }

        try {
            $reset = json_decode(($this->resetOpcache)($script, ''), true);

            if (! is_array($reset) || ($reset['reset'] ?? false) !== true) {
                return ['outcome' => 'failed'];
            }

            foreach (range(1, 5) as $attempt) {
                $status = json_decode(($this->resetOpcache)($script, 'status=1'), true);
                $after = is_array($status) && is_array($status['before'] ?? null) ? $status['before'] : [];

                if (($after['restart_pending'] ?? true) === false) {
                    return ['outcome' => 'reset', 'cache_full' => $after['cache_full'] ?? null, 'before' => $reset['before'] ?? null, 'after' => $after];
                }

                ($this->sleep)(1_000_000);
            }

            return ['outcome' => 'pending', 'before' => $reset['before'] ?? null];
        } catch (Throwable) {
            return ['outcome' => 'failed'];
        }
    }

    /** @param Closure(): void $operation */
    private function step(string $name, string $errorCode, Closure $operation): void
    {
        try {
            $operation();
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (NodeProvisioningException $exception) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: $errorCode,
                message: "Runtime handoff step [{$name}] failed at [{$exception->step}]: {$exception->getMessage()}",
                status: 500,
                previous: $exception,
                result: $exception->result,
            );
        } catch (Throwable $exception) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: $errorCode,
                message: "Runtime handoff step [{$name}] failed: {$exception->getMessage()}",
                status: 500,
                previous: $exception,
            );
        }
    }
}
