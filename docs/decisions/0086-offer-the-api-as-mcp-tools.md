---
title: "ADR 0086: Offer the API as MCP tools"
sidebarTitle: "0086 Offer the API as MCP tools"
description: "Proposed. The Gateway serves an MCP server whose tools are generated from the API description and run as internal API requests under the caller's WireGuard identity."
---

# ADR 0086: Offer the API as MCP tools

The Gateway serves a Model Context Protocol (MCP) server built on Laravel MCP. Its tools are generated from the OpenAPI description of the Gateway API, one tool for each operation, and each tool call runs as an internal API request that carries the caller's WireGuard address. An agent gets the complete API surface without the CLI, and the MCP path cannot drift from the HTTP path.

## Status

Proposed.

## Context

Agents operate Orbit through the CLI today. Each action costs a process start, human-oriented output to parse or a `--json` flag to remember, and a CLI install with a trusted Gateway profile on the machine the agent runs on. MCP gives an agent typed tools with input schemas instead.

The Gateway API already is the complete action surface: the CLI vocabulary test requires a CLI command for every named route, and [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client) builds `orbit top` on the same requests. The API identifies its caller by WireGuard source address and enforces directed node access per serving Node. `bin/docs-openapi` already derives an OpenAPI document with operation identifiers, parameters, and request body schemas from the routes, form requests, and data classes.

What matters when comparing options is parity with the API at all times, one enforcement point for identity and access, and a context cost an agent can afford with more than a hundred operations.

## Decision

- The Gateway must serve MCP over streamable HTTP at `/mcp` through `App\Http\Mcp\OrbitServer`, a Laravel MCP server.
- The tool catalogue must be generated, not hand-written. `bin/mcp-tools` derives `apps/gateway/resources/mcp/tools.json` from `docs/openapi.json`: the tool name is the operation identifier, and the input schema is the operation's path parameters, query parameters, and JSON body fields in one object. Machine callbacks (realtime channel signature, Grafana access check, App instance wake page, schedule completion report) have no tool.
- A tool call must run as an internal request through the HTTP kernel with the caller's `REMOTE_ADDR`. The active WireGuard peer check, directed node access, validation, redaction, request correlation, and command activity therefore apply to a tool call exactly as they apply to the same HTTP request. MCP code must not reimplement any of them.
- The MCP endpoints must require an active WireGuard peer before listing tools. They add no token, OAuth flow, or second identity.
- A tool must return the API's JSON document unchanged. A failure must return an MCP tool error that carries the API error envelope and the HTTP status. A streamed operation (`instance-deploy`, `instance-rollback`) must run to completion and return its progress events in order.
- The Gateway must serve the same catalogue at `/mcp/search` behind Laravel MCP's `search_tools` and `execute_tools`, for a client that loads every listed tool into its context.
- A Gateway test must fail when an API route has no tool or a tool has no route.

## Rejected alternatives

- Hand-written tool classes for each operation: rejected because more than a hundred classes would repeat the form requests' rules and drift from them with every API change.
- Tools that call Actions directly: rejected because identity, node access, validation, and activity recording live at the HTTP boundary, and a second entry into the Actions would need a second copy of each.
- An MCP server in the CLI that wraps commands: rejected because it keeps the process start and the CLI install on the agent's machine, and it parses output the API already returns as JSON.
- Token or OAuth authentication for MCP: rejected because the API's identity is the WireGuard address, and a second credential would create a second authorization model for the same actions.
- A small set of coarse tools such as one `orbit` tool that takes a command string: rejected because it discards input schemas, the main benefit MCP has over the CLI for an agent.

## Consequences

- An agent on any active Node can run every operator action through MCP with typed inputs and the API's own error codes.
- A new API operation becomes a tool by running `bin/docs-openapi` and `bin/mcp-tools`; the coverage test fails until the manifest is regenerated.
- Tool descriptions and schemas are only as good as the OpenAPI document. A weak form request description is a weak tool description, fixed in the form request.
- The full catalogue is large. A client without on-demand tool loading uses `/mcp/search`, which costs one extra search call per unfamiliar action.
- A deployment as a tool holds the MCP request open until it ends and reports progress only in the final result.
- Client-side commands have no tool because they have no API operation: Gateway profile management, `realtime:tail`, and `orbit top`.
- The manifest adds a generated file that must be committed with API changes, alongside `docs/openapi.json`.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: builds on the API identity and node access model; complements [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client)
- Detail: [MCP server](/reference/mcp)
- Verify: `apps/gateway/tests/Feature/Mcp/McpServerTest.php`, `ToolManifestCoverageTest.php`, `apps/gateway/tests/Unit/Mcp/ApiResultTest.php`; `bin/mcp-tools --check`
