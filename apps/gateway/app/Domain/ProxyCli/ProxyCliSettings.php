<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliSettings
{
    public const string Enabled = 'proxycli.enabled';

    public const string NodeId = 'proxycli.node_id';

    public const string CacheConnection = 'proxycli.cache_connection';

    public const string CliproxyUrl = 'proxycli.cliproxy_url';

    public const string CliproxyManagementKey = 'proxycli.cliproxy_management_key';

    public const string ReadToken = 'proxycli.read_token';

    public const string ControlToken = 'proxycli.control_token';

    public const string ProcessPort = 'proxycli.process_port';
}
