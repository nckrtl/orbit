<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsCredentialsData;
use App\Models\Node;

interface MetricsCredentialManager
{
    public function passwordForConvergence(Node $node): string;

    public function verifyActive(Node $node): void;

    public function purge(Node $node): void;

    /** The active credential, verified against Grafana, for an operator to sign in with. */
    public function credentials(): MetricsCredentialsData;

    /**
     * The active credential as stored, for the Gateway's own Grafana queries. Each query
     * authenticates on its own, so a rejected credential fails that query instead of costing a
     * separate verification round trip first.
     */
    public function storedCredentials(): MetricsCredentialsData;

    public function reset(): MetricsCredentialsData;
}
