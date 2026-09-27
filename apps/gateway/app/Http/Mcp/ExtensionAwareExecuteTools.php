<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Extensions\ExtensionStore;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\ToolSearch;

final class ExtensionAwareExecuteTools extends ExecuteTools
{
    public function __construct(ToolSearch $catalog, int $maxToolCalls)
    {
        parent::__construct($catalog, $maxToolCalls);
    }

    /** @return array<int, Response> */
    #[\Override]
    public function handle(Request $request): array
    {
        // Keep Laravel MCP's batch validation and call limit ahead of all invocation.
        $request->validate([
            'calls' => ['required', 'array', 'min:1', 'max:'.$this->maxToolCalls],
            'calls.*' => ['array:name,arguments'],
            'calls.*.name' => ['required', 'string', 'max:255'],
            'calls.*.arguments' => ['array'],
        ]);

        $calls = $request->get('calls');
        if (! is_array($calls) || ! array_is_list($calls)) {
            throw ValidationException::withMessages(['calls' => 'The calls field must be a list.']);
        }
        foreach ($calls as $index => $call) {
            $arguments = $call['arguments'] ?? [];
            if ($arguments !== [] && array_is_list($arguments)) {
                throw ValidationException::withMessages(["calls.{$index}.arguments" => 'The arguments field must be an object.']);
            }
        }

        $definitions = ToolManifest::default()->definitions();
        $extensions = app(ExtensionStore::class);
        $results = [];
        $notifications = [];
        $maxOutputBytes = config('mcp.tool_search.max_output_bytes', 65_536);
        $maxOutputBytes = is_int($maxOutputBytes) ? max(256, $maxOutputBytes) : 65_536;

        foreach ($calls as $index => $call) {
            $disabledExtension = null;
            foreach ($definitions as $definition) {
                if ($call['name'] === $definition->name && $definition->extension !== null
                    && ! $extensions->enabled($definition->extension)) {
                    $disabledExtension = $definition->extension;
                    break;
                }
            }

            $lastResult = null;
            if ($disabledExtension !== null) {
                $error = json_encode([
                    'status' => 409,
                    'error' => [
                        'code' => 'extension.disabled',
                        'message' => "The {$disabledExtension} extension is disabled.",
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $lastResult = [
                    'name' => $call['name'],
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => $error === false ? 'Extension disabled.' : $error]],
                ];
            } else {
                $responses = $this->catalog->execute([$call], $request);
                foreach ($responses as $response) {
                    if ($response->isNotification()) {
                        $notifications[] = $response;

                        continue;
                    }

                    $content = $response->content()->toArray();
                    $text = $content['text'] ?? null;
                    if (! is_string($text)) {
                        continue;
                    }
                    try {
                        $payload = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        continue;
                    }
                    if (is_array($payload) && is_array($payload['error'] ?? null)
                        && ($payload['error']['kind'] ?? null) === 'OutputLimitExceeded') {
                        return [...$notifications, $this->catalog->response([
                            'ok' => false,
                            'error' => [
                                'kind' => 'OutputLimitExceeded',
                                'message' => "The tool output exceeded {$maxOutputBytes} bytes.",
                            ],
                            'completedToolCalls' => count($results) + 1,
                            'attemptedToolCalls' => $index + 1,
                        ], true)];
                    }
                    if (is_array($payload) && is_array($payload['results'] ?? null)) {
                        $lastResult = $payload['results'][0] ?? null;
                        if (is_array($lastResult)) {
                            break;
                        }
                    }
                }
            }

            if (! is_array($lastResult)) {
                $lastResult = [
                    'name' => $call['name'],
                    'content' => [['type' => 'text', 'text' => 'Tool execution returned no result.']],
                    'isError' => true,
                ];
            }
            $results[] = $lastResult;

            $size = strlen((string) json_encode(
                ['ok' => true, 'results' => []],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            foreach ($results as $entry) {
                $size += strlen((string) json_encode(
                    $entry,
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )) + 1;
            }
            if ($size > $maxOutputBytes) {
                return [...$notifications, $this->catalog->response([
                    'ok' => false,
                    'error' => [
                        'kind' => 'OutputLimitExceeded',
                        'message' => "The tool output exceeded {$maxOutputBytes} bytes.",
                    ],
                    'completedToolCalls' => count($results),
                    'attemptedToolCalls' => $index + 1,
                ], true)];
            }

            if (($lastResult['isError'] ?? false) === true) {
                return [...$notifications, $this->catalog->response(['ok' => false, 'results' => $results], true)];
            }
        }

        return [...$notifications, $this->catalog->response(['ok' => true, 'results' => $results])];
    }
}
