<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

/**
 * Withdraws a Route's projections. Each Node-side step leaves the Nodes in `$skippedNodeIds` alone, so
 * an offline removal changes nothing on a Node it cannot reach.
 */
interface RouteRemovalProjector
{
    /**
     * The Nodes the removal changes, each with the steps that act on it.
     *
     * @return list<RouteRemovalNode>
     */
    public function nodes(Route $route): array;

    public function cleanupDns(Route $route): void;

    /** @param list<int> $skippedNodeIds */
    public function cleanupCertificates(Route $route, array $skippedNodeIds = []): void;

    /** @param list<int> $skippedNodeIds */
    public function cleanupCaddy(Route $route, array $skippedNodeIds = []): void;

    /** @param list<int> $skippedNodeIds */
    public function cleanupFirewall(Route $route, array $skippedNodeIds = []): void;

    /**
     * Converges PHP-FPM on each development target's Node, so the pools of the Route's withdrawn sites leave.
     *
     * @param  list<int>  $skippedNodeIds
     */
    public function cleanupPhp(Route $route, array $skippedNodeIds = []): void;
}
