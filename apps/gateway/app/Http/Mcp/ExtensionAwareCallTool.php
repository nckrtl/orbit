<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Extensions\ExtensionStore;
use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class ExtensionAwareCallTool implements Method
{
    /** @return JsonRpcResponse|Generator<JsonRpcResponse> */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->get('name');
        if (is_string($name)) {
            $known = false;

            foreach (ToolManifest::default()->definitions() as $definition) {
                if ($definition->name !== $name) {
                    continue;
                }

                $known = true;

                if ($definition->extension !== null && ! app(ExtensionStore::class)->enabled($definition->extension)) {
                    $message = json_encode([
                        'status' => 409,
                        'error' => [
                            'code' => 'extension.disabled',
                            'message' => "The {$definition->extension} extension is disabled.",
                        ],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    return JsonRpcResponse::result($request->id, [
                        'content' => [['type' => 'text', 'text' => $message === false ? 'The extension is disabled.' : $message]],
                        'isError' => true,
                    ]);
                }
            }

            // A client keeps the tool list it fetched when it connected. After a release removes or renames a
            // tool, the client can still call the old name, so the error tells it how to refresh the list.
            if (! $known) {
                throw new JsonRpcException(
                    "Tool [{$name}] not found. The Gateway's tool list may have changed since this client listed it: list the tools again, or reconnect the MCP server.",
                    -32602,
                    $request->id,
                );
            }
        }

        return (new CallTool)->handle($request, $context);
    }
}
