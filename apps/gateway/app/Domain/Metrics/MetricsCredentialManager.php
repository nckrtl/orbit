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

    public function credentials(): MetricsCredentialsData;

    public function reset(): MetricsCredentialsData;
}
