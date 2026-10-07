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

    /** @var Closure(string): string */
    private Closure $resetOpcache;

    /**
     * @param  (Closure(string): (string|false))|null  $readLivePool
     * @param  (Closure(string): string)|null  $resetOpcache  runs the reset script inside the pool and returns its JSON
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
    ) {
        $this->readLivePool = $readLivePool ?? static fn (string $path): string|false => @file_get_contents($path);
        $this->resetOpcache = $resetOpcache ?? static fn (string $script): string => new FpmScriptRequest()->request($script);
    }

    /**
     * @return HandoffResult
     *
     * @throws GatewayReleaseException
     */
    public function run(): array
    {
        $gateway = $this->gateway();
        $generation = $this->cleanup->generation();
        $caddy = $this->caddy($gateway);
        $fpm = $this->fpm();
        $this->step('units', 'gateway.release_units_failed', function (): void {
            $this->hibernator->converge();
            $this->agentView->converge();
        });
        $opcache = $this->opcache();
        $scheduler = $this->scheduler->handoff($gateway);
        $cleanup = $this->cleanup->resume($generation, $scheduler['outcome'] === 'restarted' || $fpm === 'reloaded');

        return [
            'caddy' => $caddy,
            'fpm' => $fpm,
            'opcache' => $opcache,
            'scheduler' => $scheduler['outcome'],
            'scheduler_unit' => $scheduler['unit'],
            'scheduler_drain' => $scheduler['drain'],
            'processes_restarted' => $scheduler['restarted'],
            'cleanup' => $cleanup['outcome'],
            'cleanup_error_code' => $cleanup['error_code'] ?? null,
            'agent_view' => 'restarted',
            'cleanup_paused' => $cleanup['paused'],
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
     * Resets the pool's OPcache, so the scripts of releases that no longer serve do not fill it. Release files never
     * change in place, so OPcache would never mark them wasted. It resets only when the live pool lets running requests
     * finish first; a failure leaves the release running with a fuller cache and does not fail it.
     */
    private function opcache(): string
    {
        $pool = ($this->readLivePool)($this->livePool);

        if (! is_string($pool) || ! str_contains($pool, 'opcache.force_restart_timeout')) {
            return 'skipped';
        }

        $release = realpath($this->applicationPath);

        try {
            $result = json_decode(($this->resetOpcache)(($release === false ? $this->applicationPath : $release).'/resources/fpm/opcache-reset.php'), true);
        } catch (Throwable) {
            return 'failed';
        }

        return is_array($result) && ($result['reset'] ?? false) === true ? 'reset' : 'failed';
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
