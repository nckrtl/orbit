<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\CaddyBuildObservation;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyLiveReader;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

/**
 * Renders the Node's Caddy build from stored state and compares it byte for byte with the live
 * `/etc/caddy/Caddyfile` (ADR 0141). A Node whose live file no build wrote, such as a foreign file or the
 * fragment layout of an earlier release, never matches; its first build backs that file up and replaces it.
 */
final readonly class NativeCaddyBuildInspector implements CaddyBuildInspector
{
    /** Roles whose convergence builds the Node, so the Node has a build even when it renders no site yet. */
    private const array CaddyRoles = [
        RoleName::Gateway,
        RoleName::Router,
        RoleName::Ingress,
        RoleName::AppDev,
        RoleName::AppProd,
        RoleName::WebSocket,
        RoleName::Analytics,
    ];

    /** Well below the 30 seconds a build waits for the lock that Doctor holds while it reads. */
    public const float ReadTimeoutSeconds = 10.0;

    /** How long Doctor waits for a running build before it reports the Node as not compared. */
    public const float LockWaitSeconds = 5.0;

    public function __construct(
        private NodeCaddyfileRenderer $renderer,
        private NodeCaddyLiveReader $live,
        private NodeCaddyBuildLock $lock,
    ) {}

    public function inspect(Node $node): ?CaddyBuildObservation
    {
        if ($node->platform !== 'linux') {
            return null;
        }

        try {
            if ($this->renderer->render($node)->sites === [] && ! $this->servesCaddyRole($node)) {
                return null;
            }

            // A running build is replacing the file. Doctor waits briefly for it, then reports the Node as
            // not compared rather than healthy. The short read keeps a build's wait for Doctor short.
            $compared = $this->lock->runIfFree($node->id, fn (): array => [
                $this->renderer->render($node),
                $this->live->read($node, self::ReadTimeoutSeconds),
            ], waitSeconds: self::LockWaitSeconds);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        if ($compared === null) {
            return CaddyBuildObservation::building();
        }

        [$caddyfile, $live] = $compared;

        $built = str_starts_with($live, NodeCaddyfileRenderer::Marker.PHP_EOL);

        return new CaddyBuildObservation(
            expectedVersion: $caddyfile->buildable() ? $caddyfile->version : null,
            liveVersion: $built ? NodeCaddyfileRenderer::version($live) : null,
            matches: $caddyfile->buildable() && $live === $caddyfile->content,
            sources: array_values(array_unique(array_map(
                static fn (CaddySite $site): string => $site->source,
                $caddyfile->sites,
            ))),
        );
    }

    private function servesCaddyRole(Node $node): bool
    {
        return NodeRole::query()
            ->where('node_id', $node->id)
            ->whereIn('role', array_map(static fn (RoleName $role): string => $role->value, self::CaddyRoles))
            ->get()
            ->contains(static fn (NodeRole $role): bool => CaddySiteRoles::serves($role));
    }
}
