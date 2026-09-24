<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

/** The reserved collector hostname. The apex stays free for the CLIProxyAPI management Route. */
final readonly class ProxyCliHostname
{
    public const string Value = 'collector.cli-proxy-api.orbit';

    public const string Apex = 'cli-proxy-api.orbit';
}
