<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

/** The reserved collector hostname. Apex stays free for a CLIProxyAPI management Route. */
final readonly class ProxyCliHostname
{
    public const string Value = 'collector.proxycli.orbit';

    public const string Apex = 'proxycli.orbit';
}
