<?php

declare(strict_types=1);

it('exposes both application Boost MCP servers from the monorepo root', function (): void {
    $configurationPath = dirname(base_path(), levels: 2).'/.codex/config.toml';
    $configuration = file_get_contents($configurationPath);

    expect($configurationPath)
        ->toBeFile()
        ->and($configuration)
        ->toBe(<<<'TOML'
            [mcp_servers.orbit-cli-boost]
            command = "php"
            args = ["orbit", "boost:mcp"]
            cwd = "apps/cli"

            [mcp_servers.orbit-gateway-boost]
            command = "php"
            args = ["artisan", "boost:mcp"]
            cwd = "apps/gateway"
            TOML."\n");
});
