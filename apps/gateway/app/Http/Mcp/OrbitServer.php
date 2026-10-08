<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Http\Middleware\ExpireOutdatedMcpSessions;
use Laravel\Mcp\Server;

/**
 * The Gateway's Model Context Protocol server: every API operation listed as its own tool.
 *
 * It is the agent-facing alternative to the CLI. The caller is identified the way the API identifies it,
 * by the WireGuard address the request arrives from.
 */
class OrbitServer extends Server
{
    #[\Override]
    protected string $name = 'Orbit Gateway';

    #[\Override]
    protected string $version = '1.0.0';

    /**
     * The tool list changes when a release changes the manifest or an extension is switched. A client then
     * learns of it when its session ends ({@see ExpireOutdatedMcpSessions}).
     *
     * @var array<string, array<string, bool>>
     */
    #[\Override]
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => true],
        self::CAPABILITY_RESOURCES => ['listChanged' => false],
        self::CAPABILITY_PROMPTS => ['listChanged' => false],
    ];

    /** One page holds the whole catalogue, so a client lists every tool in one request. */
    #[\Override]
    public int $maxPaginationLength = 500;

    #[\Override]
    public int $defaultPaginationLength = 500;

    #[\Override]
    protected string $instructions = <<<'MARKDOWN'
        Operate an Orbit fleet through the Gateway. Every tool is one Gateway API operation and returns the API's JSON, usually `{"data": ..., "meta": {"request_id": ...}}`.

        - You act as the Node whose WireGuard address your connection comes from. A `403` with `peer.identity_unknown` means that address is not an active Node; `node.access_denied` means your Node has no access edge to the serving Node.
        - Tool names follow `<family>-<verb>`, for example `node-list`, `instance-show`, `process-restart`. List or show a record before changing it, and pass numeric ids unless the schema says otherwise.
        - A failed call returns the API error envelope `{"status", "error": {"code", "message", "details"}}`. Use `error.code` to decide what to do next; validation failures list the offending fields in `details`.
        - `instance-deploy` and `instance-rollback` run to completion and return every progress event under `events`; the last event carries the result.
        - Destructive tools are annotated as such. Confirm with the user before removing Nodes, Instances, databases, or Routes.
        MARKDOWN;

    #[\Override]
    protected function boot(): void
    {
        $this->methods['tools/call'] = ExtensionAwareCallTool::class;
        $this->methods['server/discover'] = InitializeHandshakeDiscover::class;
        $this->tools = $this->catalogue();
    }

    /** @return list<ApiOperationTool> */
    protected function catalogue(): array
    {
        return ToolManifest::default()->tools(app(ApiDispatcher::class));
    }
}
